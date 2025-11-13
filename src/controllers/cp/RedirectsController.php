<?php

namespace paxxion\craftredirector\controllers\cp;

use Carbon\Carbon;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use DateTime;
use DateTimeZone;
use paxxion\craftredirector\assets\RedirectorAssets;
use paxxion\craftredirector\records\NotFoundRecord;
use paxxion\craftredirector\records\RedirectRecord;
use paxxion\craftredirector\Redirector;
use yii\base\DynamicModel;
use yii\data\Pagination;
use yii\web\Response;

class RedirectsController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;
    
    public function actionRedirectToIndex():Response
    {
        return $this->redirect(UrlHelper::cpurl('redirector/audit'));
    }

    public function actionIndex():Response
    {
        Craft::$app->getView()->registerAssetBundle(RedirectorAssets::class);

        $returnSites = [
            'all' => [
                'label' => Craft::t('pxx-redirector', 'All sites'),
                'value' => '',
            ]
        ];
        $sites = Craft::$app->getSites()->getAllSites();
        foreach ($sites as $site) {
            $returnSites[$site->handle] = [
                'label' => $site->name,
                'value' => $site->handle,
            ];
        }

        return $this->renderTemplate('pxx-redirector/cp/redirects/index', [
            'sites' => $returnSites,
        ]);
    }

    public function actionGetRows():Response
    {
        $this->requirePostRequest();

        $postData = Craft::$app->getRequest()->getBodyParams();
        $page = (int)$postData['page'] ?? 1;
        $pageItems = (int)$postData['pageItems'] ?? 50;        

        $sites = Craft::$app->getSites()->getAllSites();
        $siteByHandle = [];
        foreach ($sites as $site) {
            $siteBaseUrl = $site->getBaseUrl();
            $scheme = parse_url($siteBaseUrl, PHP_URL_SCHEME);
            $host = parse_url($siteBaseUrl, PHP_URL_HOST);
            $url = $scheme . '://' . $host;

            $siteByHandle[$site->handle] = [
                'id' => $site->id,
                'url' => $url,
            ];
        }

        $redirectsRows = $this->getQuery($postData, $siteByHandle);
        $redirectsRows = $redirectsRows->joinWith(['site']);

        // pagination
        $totalCount = (clone $redirectsRows)->count();
        $pagination = new Pagination([
            'totalCount' => $totalCount,
            'defaultPageSize' => $pageItems,
            'pageSize' => $pageItems,
            'page' => max($page - 1, 0),
        ]);
        if ($page > $pagination->getPageCount()) {
            $pagination->setPage(0);
        };
        
        // redirects rows
        $redirectsRows = $redirectsRows
            ->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();

        $returnRedirectsRows = [];
        foreach ($redirectsRows as $redirectRow) {
            $tzDateLastHit = null;
            if ($redirectRow->dateLastHit) {
                $dateLastHit = Carbon::parseFromLocale($redirectRow->dateLastHit, null, 'UTC');
                $tzDateLastHit = $dateLastHit->setTimezone(Craft::$app->getTimeZone());
            }

            $returnredirectRow = [
                'id' => $redirectRow->id,
                'uid' => $redirectRow->uid,
                'enabled' => $redirectRow->enabled ? true : false,
                'siteName' => $redirectRow->site->name ?? Craft::t('pxx-redirector', 'All sites'),
                'oldUrl' => $redirectRow->oldUrl,
                'newUrl' => $redirectRow->newUrl,
                'matchType' => $redirectRow->matchType,
                'priority' => $redirectRow->priority,
                'httpCode' => $redirectRow->httpCode,
                'totalHits' => $redirectRow->totalHits,
                'dateLastHit' => $tzDateLastHit ? $tzDateLastHit->format('d/m/Y H:i:s') : '',
                'selected' => false,
            ];

            $returnRedirectsRows[] = $returnredirectRow;
        }

        $rowsFrom = (($pagination->getPage() + 1) * $pagination->getPageSize()) - ($pagination->getPageSize() - 1);
        $rowsTo = (($pagination->getPage() + 1) * $pagination->getPageSize()) > $pagination->totalCount
            ? $pagination->totalCount
            : (($pagination->getPage() + 1) * $pagination->getPageSize());

        return $this->asJson([
            'dataRows' => $returnRedirectsRows,
            'pagination' => [
                'rowsFrom' => $rowsFrom,
                'rowsTo' => $rowsTo,
                'totalRows' => $pagination->totalCount,
                'page' => $pagination->getPage() + 1,
                'pages' => $pagination->getPageCount(),
            ],
        ]);
    }

    public function actionUpdateRows():Response
    {
        $this->requirePostRequest();

        $postData = Craft::$app->getRequest()->getBodyParams();

        $rowsToUpdate = $postData['rows'];
        
        $httpCode = $postData['httpCode'] ?? null;
        if (!is_null($httpCode)) {
            if (!in_array($httpCode, [ 301, 302, 307, 308, 410 ])) {
                $httpCode = 301;
            }
    
            RedirectRecord::updateAll([ 'httpCode' => $httpCode ], [ 'id' => $rowsToUpdate ]);
        }

        $status = $postData['status'] ?? null;
        if (!is_null($status)) {
            $enabled = $status == 'enabled';

            RedirectRecord::updateAll([ 'enabled' => $enabled ], [ 'id' => $rowsToUpdate ]);
        }

        return $this->asJson([
            'status' => 'success',
        ]);
    }

    public function actionDeleteRows():Response
    {
        $this->requirePostRequest();

        $postData = Craft::$app->getRequest()->getBodyParams();

        $rowsToDelete = $postData['rows'];

        RedirectRecord::deleteAll([ 'id' => $rowsToDelete ]);

        return $this->asJson([
            'status' => 'success',
        ]);
    }

    public function actionExportCsv()
    {
        $this->requirePostRequest();

        $postData = Craft::$app->getRequest()->getBodyParams();

        $columns = [
            'enabled',
            'siteId',
            'oldUrl',
            'newUrl',
            'matchType',
            'priority',
            'httpCode',
            'totalHits',
            'dateLastHit',
        ];

        $sites = Craft::$app->getSites()->getAllSites();
        $siteByHandle = [];
        foreach ($sites as $site) {
            $siteBaseUrl = $site->getBaseUrl();
            $scheme = parse_url($siteBaseUrl, PHP_URL_SCHEME);
            $host = parse_url($siteBaseUrl, PHP_URL_HOST);
            $url = $scheme . '://' . $host;

            $siteByHandle[$site->handle] = [
                'id' => $site->id,
                'url' => $url,
            ];
        }

        $redirects = $this->getQuery($postData, $siteByHandle);
        $redirects = $redirects->select($columns)->asArray()->all();
        
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $columns);

        if (!empty($redirects)) {
            foreach ($redirects as $row) {
                $csvRow = [];
                foreach ($columns as $column) {
                    $csvRow[$column] = $row[$column];
                }

                fputcsv($fp, $csvRow);
            }
        }

        rewind($fp);
        $csvContent = stream_get_contents($fp);
        fclose($fp);

        return Craft::$app->response->sendContentAsFile(
            $csvContent,
            'redirects.csv',
            [
                'mimeType' => 'text/csv',
                'inline' => false,
            ]
        );
    }

    public function actionImport()
    {
        Craft::$app->getView()->registerAssetBundle(RedirectorAssets::class);

        return $this->renderTemplate('pxx-redirector/cp/redirects/import', []);
    }

    public function actionImportCsv():Response
    {
        $this->requirePostRequest();

        $postData = Craft::$app->getRequest()->getBodyParams();

        $separator = $postData['separator'];
        $uploadedFile = UploadedFile::getInstanceByName('file');

        if (!$uploadedFile) {
            Craft::$app->response->statusCode = 400;
            return $this->asJson([
                'error' => Craft::t('pxx-redirector', 'Required'),
            ]);
        }

        if (strtolower($uploadedFile->getExtension()) !== 'csv') {
            Craft::$app->response->statusCode = 400;
            return $this->asJson([
                'error' => Craft::t('pxx-redirector', 'Upload a CSV file'),
            ]);
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        try
        {
            $tmpPath = $uploadedFile->tempName;

            $rows = [];
            if (($handle = fopen($tmpPath, 'r')) !== false) {
                $row = 0;
                $headers = [];

                while (($data = fgetcsv($handle, 1000, $separator)) !== false) {
                    // first row is headers
                    if ($row === 0) {
                        // remove BOM UTF-8
                        $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
                        $headers = array_map('trim', $data);
                    } else {
                        // combine headers and values for each row
                        $record = array_combine($headers, $data);
                        if ($record) {
                            $rows[] = $record;
                        }
                    }
                    $row++;
                }
                
                fclose($handle);

                if (!in_array('oldUrl', $headers)) {
                    Craft::$app->response->statusCode = 400;
                    return $this->asJson([
                        'error' => Craft::t('pxx-redirector', 'Missing oldUrl column'),
                    ]);
                }

                if (!in_array('newUrl', $headers)) {
                    Craft::$app->response->statusCode = 400;
                    return $this->asJson([
                        'error' => Craft::t('pxx-redirector', 'Missing newUrl column'),
                    ]);
                }

                $siteIds = [];
                $sites = Craft::$app->getSites()->getAllSites();
                foreach ($sites as $site) {
                    $siteIds[] = $site->id;
                }

                foreach ($rows as $row) {
                    $oldUrl = $row['oldUrl'];
                    $newUrl = $row['newUrl'];

                    if ($oldUrl == $newUrl) continue;

                    if (str_starts_with($oldUrl, 'http')) {
                        $path = '/' . trim(parse_url($oldUrl, PHP_URL_PATH), '/');
                        $query = parse_url($oldUrl, PHP_URL_QUERY);

                        $oldUrl = $path . ($query ? '?' . $query : '');
                    }
                    else {
                        $oldUrl = '/' . trim($row['oldUrl'], '/');
                    }

                    if (str_starts_with($newUrl, 'http')) {
                        $path = '/' . trim(parse_url($newUrl, PHP_URL_PATH), '/');
                        $query = parse_url($newUrl, PHP_URL_QUERY);

                        $newUrl = $path . ($query ? '?' . $query : '');
                    }
                    else {
                        $newUrl = '/' . trim($row['newUrl'], '/');
                    }

                    $enabled = in_array('enabled', $headers)
                        ? $row['enabled'] == '' || $row['enabled']
                        : true;
                    
                    $siteId = in_array('siteId', $headers)
                        ? (in_array($row['siteId'], $siteIds) ? $row['siteId'] : null)
                        : null;

                    $matchType = in_array('matchType', $headers)
                        ? (
                            in_array($row['matchType'], ['exact', 'regex'])
                                ? $row['matchType']
                                : 'exact'
                        )
                        : 'exact';

                    $priority = in_array('priority', $headers)
                        ? (
                            $matchType == 'regex'
                                ? (is_numeric($row['priority']) ? $row['priority'] : 10)
                                : null
                        )
                        : (
                            $matchType == 'regex'
                                ? 10
                                : null
                        );

                    $httpCode = in_array('httpCode', $headers)
                        ? (in_array($row['httpCode'], [301, 302, 307, 308, 410]) ? $row['httpCode'] : 301)
                        : 301;

                    $redirectRecord = RedirectRecord::find()
                        ->where([
                            'siteId' => $siteId,
                            'oldUrl' => $oldUrl,
                        ])
                        ->one();
                    if (is_null($redirectRecord)) {
                        $redirectRecord = new RedirectRecord();
                    }
                    $redirectRecord->enabled = $enabled;
                    $redirectRecord->siteId = $siteId;
                    $redirectRecord->oldUrl = $oldUrl;
                    $redirectRecord->newUrl = $newUrl;
                    $redirectRecord->matchType = $matchType;
                    $redirectRecord->priority = $priority;
                    $redirectRecord->httpCode = $httpCode;

                    $redirectRecord->save();
                }
            } else {
                $transaction->rollBack();

                Craft::$app->response->statusCode = 500;
                return $this->asJson([]);
            }

            $transaction->commit();

            return $this->asJson([]);
        }
        catch (\Throwable $ex) {
            $transaction->rollBack();

            Craft::error('Redirector - error import csv: ' . $ex->getMessage());

            Craft::$app->response->statusCode = 500;
            return $this->asJson([]);
        }
    }

    public function actionEntry($uid):Response
    {
        Craft::$app->getView()->registerAssetBundle(RedirectorAssets::class);

        $returnSites = [
            'all' => [
                'label' => Craft::t('pxx-redirector', 'All sites'),
                'value' => '',
            ]
        ];
        $sites = Craft::$app->getSites()->getAllSites();
        foreach ($sites as $site) {
            $returnSites[$site->handle] = [
                'label' => $site->name,
                'value' => $site->handle,
            ];
        }

        $redirectData = [
            'uid' => $uid,
            'enabled' => true,
            'siteHandle' => '',
            'oldUrl' => '',
            'newUrl' => '',
            'matchType' => '',
            'priority' => '',
            'httpCode' => '',
        ];

        if ($uid == 'new') {
            $queryUid = Craft::$app->getRequest()->getQueryParam('uid');
            if (!is_null($queryUid)) {
                $notFoundRecord = NotFoundRecord::find()->where(['uid' => $queryUid])->one();
                if (is_null($notFoundRecord)) {
                    return $this->redirect(UrlHelper::cpurl('redirector/audit'));
                }

                $redirectData['siteHandle'] = $notFoundRecord->site->handle;
                $redirectData['oldUrl'] = $notFoundRecord->url;
                $redirectData['matchType'] = 'exact';
            }
        }
        else {
            $redirectRecord = RedirectRecord::find()->where(['uid' => $uid])->one();
            if (is_null($redirectRecord)) {
                return $this->redirect(UrlHelper::cpurl('redirector/redirects'));
            }

            $redirectData['enabled'] = $redirectRecord->enabled ? true : false;
            $redirectData['siteHandle'] = $redirectRecord->site->handle ?? '';
            $redirectData['oldUrl'] = $redirectRecord->oldUrl;
            $redirectData['newUrl'] = $redirectRecord->newUrl;
            $redirectData['matchType'] = $redirectRecord->matchType;
            $redirectData['priority'] = $redirectRecord->priority;
            $redirectData['httpCode'] = $redirectRecord->httpCode;
        }

        $errors = [];
        foreach ($redirectData as $key => $value) {
            $errors[$key] = '';
        }

        $plugin = Redirector::getInstance();
        $settings = $plugin->getSettings();
        $apiKey = $settings->getOpenAiApiKey();

        return $this->renderTemplate('pxx-redirector/cp/redirects/entry', [
            'isNew' => $uid == 'new',
            'sites' => $returnSites,
            'data' => $redirectData,
            'errors' => $errors,
            'enableAi' => $apiKey !== '',
        ]);
    }

    public function actionSaveRedirect():Response
    {
        $this->requirePostRequest();

        $postData = Craft::$app->getRequest()->getBodyParams();

        // validation
        $validationRules = [
            [ [ 'oldUrl', 'newUrl', 'matchType', 'httpCode' ], 'required', 'message' => Craft::t('pxx-redirector', 'Required field') ],
            [ [ 'priority' ], 'required', 'when' => function ($model) { return $model->matchType === 'regex'; }, 'message' => Craft::t('pxx-redirector', 'Required field') ],
            [ [ 'oldUrl', 'newUrl' ], 'match', 'pattern' => '/^https?:\/\//i', 'not' => true, 'message' => Craft::t('pxx-redirector', 'Only relative paths allowed') ],
            [ [ 'newUrl' ], 'compare', 'compareAttribute' => 'oldUrl', 'operator' => '!=', 'message' => 'The new url cannot be equal to the old url' ],
            [ [ 'httpCode', 'priority' ], 'integer', 'message' => Craft::t('pxx-redirector', 'Invalid format') ],
            [ [ 'siteHandle', 'priority' ], 'default', 'value' => NULL ],
        ];
        $validate = DynamicModel::validateData($postData, $validationRules);
        if ($validate->hasErrors()) {
            $errors = $validate->getErrors();

            Craft::$app->response->statusCode = 400;
            return $this->asJson($errors);
        }

        $cleanPostData = $validate->getAttributes();

        $httpCode = $cleanPostData['httpCode'];
        if (!in_array($httpCode, [ 301, 302, 307, 308, 410 ])) {
            $httpCode = 301;
        }

        $siteIdByHandle = [];
        $sites = Craft::$app->getSites()->getAllSites();
        foreach ($sites as $site) {
            $siteIdByHandle[$site->handle] = $site->id;
        }

        // save redirect
        $redirectRecord = null;
        if ($postData['uid'] != 'new') {
            $redirectRecord = RedirectRecord::find()
                ->where([
                    'uid' => $postData['uid'],
                ])
                ->one();
        }

        $checkRedirectRecord = RedirectRecord::find()
            ->where([
                'siteId' => $siteIdByHandle[$cleanPostData['siteHandle']] ?? NULL,
                'oldUrl' => '/' . trim($cleanPostData['oldUrl'], '/')
            ])
            ->one();
        if (!is_null($checkRedirectRecord) && (is_null($redirectRecord) || $checkRedirectRecord->id != $redirectRecord->id)) {
            Craft::$app->response->statusCode = 400;
            return $this->asJson([
                'oldUrl' => [ 'A redirect for this url already exists' ]
            ]);
        }
        
        if (is_null($redirectRecord)) {
            $redirectRecord = new RedirectRecord();
        }
        $redirectRecord->enabled = $cleanPostData['enabled'];
        $redirectRecord->siteId = $siteIdByHandle[$cleanPostData['siteHandle']] ?? NULL;
        $redirectRecord->oldUrl = '/' . trim($cleanPostData['oldUrl'], '/');
        $redirectRecord->newUrl = '/' . trim($cleanPostData['newUrl'], '/');
        $redirectRecord->matchType = $cleanPostData['matchType'];
        $redirectRecord->priority = $cleanPostData['priority'];
        $redirectRecord->httpCode = $httpCode;
        $redirectRecord->save();

        return $this->asJson([
            'uid' => $redirectRecord->uid,
            'enabled' => $redirectRecord->enabled,
            'siteHandle' => $redirectRecord->site->handle ?? '',
            'oldUrl' => $redirectRecord->oldUrl,
            'newUrl' => $redirectRecord->newUrl,
            'matchType' => $redirectRecord->matchType,
            'priority' => $redirectRecord->priority,
            'httpCode' => $redirectRecord->httpCode,
        ]);
    }

    private function getQuery($postData, $siteByHandle)
    {
        $sortField = $postData['sortField'];
        $sortDirection = strtoupper($postData['sortDirection']);
        $filters = $postData['filters'];

        // query
        $filterConditions = ['and'];
        $redirectsRows = RedirectRecord::find();

        // filters enabled
        if ($filters['enabled']) {
            $filterConditions[] = [
                '{{%redirector_redirects}}.enabled' => $filters['enabled'] == 'yes' ? true : false,
            ];
        }

        // filters site
        if ($filters['site']) {
            $filterConditions[] = [
                'siteId' => $siteByHandle[$filters['site']]['id']
            ];
        }

        // filters old url
        if ($filters['oldUrl']) {
            $filterConditions[] = [
                'like',
                'oldUrl',
                $filters['oldUrl'],
            ];
        }

        // filters new url
        if ($filters['newUrl']) {
            $filterConditions[] = [
                'like',
                'newUrl',
                $filters['newUrl'],
            ];
        }

        // match type
        if ($filters['matchType']) {
            $filterConditions[] = [
                'matchType' => $filters['matchType']
            ];
        }

        // http code
        if ($filters['httpCode']) {
            $filterConditions[] = [
                'httpCode' => $filters['httpCode']
            ];
        }

        // filters date from
        if ($filters['dateFrom']) {
            $dateFrom = new DateTime($filters['dateFrom'], new DateTimeZone(Craft::$app->getTimeZone()));
            $dateFrom->setTimezone(new DateTimeZone('UTC'));
            $filterConditions[] = [
                '>=',
                'dateLastHit',
                $dateFrom->format('Y-m-d H:i:s')
            ];
        }

        // filters date to
        if ($filters['dateTo']) {
            $dateTo = new DateTime($filters['dateTo'], new DateTimeZone(Craft::$app->getTimeZone()));
            $dateTo->setTime(23, 59, 59);
            $dateTo->setTimezone(new DateTimeZone('UTC'));
            $filterConditions[] = [
                '<=',
                'dateLastHit',
                $dateTo->format('Y-m-d H:i:s')
            ];
        }
        
        // apply filter conditions
        $redirectsRows = $redirectsRows->where($filterConditions);

        // sort order
        $redirectsRows = $redirectsRows
            ->orderBy($sortField . ' ' . $sortDirection . ', {{%redirector_redirects}}.id ' . $sortDirection);

        return $redirectsRows;
    }
}
