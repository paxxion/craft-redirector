<?php

namespace paxxion\craftredirector\controllers\cp;

use Carbon\Carbon;
use Craft;
use craft\web\Controller;
use craft\web\Response;
use DateTime;
use DateTimeZone;
use paxxion\craftredirector\assets\RedirectorAssets;
use paxxion\craftredirector\records\NotFoundRecord;
use yii\data\Pagination;

class AuditController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

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

        return $this->renderTemplate('pxx-redirector/cp/audit/index', [
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
        
        $notFoundRows = $this->getQuery($postData, $siteByHandle);
        $notFoundRows = $notFoundRows->joinWith(['site']);

        // pagination
        $totalCount = (clone $notFoundRows)->count();
        $pagination = new Pagination([
            'totalCount' => $totalCount,
            'defaultPageSize' => $pageItems,
            'pageSize' => $pageItems,
            'page' => max($page - 1, 0),
        ]);
        if ($page > $pagination->getPageCount()) {
            $pagination->setPage(0);
        };
        
        // not found rows
        $notFoundRows = $notFoundRows
            ->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();

        $returnNotFoundRows = [];
        foreach ($notFoundRows as $notFoundRow) {
            $dateLastHit = Carbon::parseFromLocale($notFoundRow->dateLastHit, null, 'UTC');
            $tzDateLastHit = $dateLastHit->setTimezone(Craft::$app->getTimeZone());

            $returnNotFoundRow = [
                'id' => $notFoundRow->id,
                'uid' => $notFoundRow->uid,
                'siteId' => $notFoundRow->site->id,
                'siteName' => $notFoundRow->site->name,
                'siteBaseUrl' => $siteByHandle[$notFoundRow->site->handle]['url'],
                'url' => $notFoundRow->url,
                'lastReferrer' => $notFoundRow->lastReferrer,
                'totalHits' => $notFoundRow->totalHits,
                'dateLastHit' => $tzDateLastHit->format('d/m/Y H:i:s'),
                'handled' => $notFoundRow->handled ? true : false,
                'selected' => false,
            ];

            $returnNotFoundRows[] = $returnNotFoundRow;
        }

        $rowsFrom = (($pagination->getPage() + 1) * $pagination->getPageSize()) - ($pagination->getPageSize() - 1);
        $rowsTo = (($pagination->getPage() + 1) * $pagination->getPageSize()) > $pagination->totalCount
            ? $pagination->totalCount
            : (($pagination->getPage() + 1) * $pagination->getPageSize());

        return $this->asJson([
            'dataRows' => $returnNotFoundRows,
            'pagination' => [
                'rowsFrom' => $rowsFrom,
                'rowsTo' => $rowsTo,
                'totalRows' => $pagination->totalCount,
                'page' => $pagination->getPage() + 1,
                'pages' => $pagination->getPageCount(),
            ],
        ]);
    }

    public function actionDeleteRows():Response
    {
        $this->requirePostRequest();

        $postData = Craft::$app->getRequest()->getBodyParams();

        $rowsToDelete = $postData['rows'];

        NotFoundRecord::deleteAll([ 'id' => $rowsToDelete ]);

        return $this->asJson([
            'status' => 'success',
        ]);
    }

    public function actionExportCsv()
    {
        $this->requirePostRequest();

        $postData = Craft::$app->getRequest()->getBodyParams();

        $columns = [
            'siteId',
            'url',
            'lastReferrer',
            'totalHits',
            'dateLastHit',
            'handled',
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
        
        $notFoundRows = $this->getQuery($postData, $siteByHandle);
        $notFoundRows = $notFoundRows->select($columns)->asArray()->all();
        
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $columns);

        if (!empty($notFoundRows)) {
            foreach ($notFoundRows as $row) {
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
            'audit.csv',
            [
                'mimeType' => 'text/csv',
                'inline' => false,
            ]
        );
    }

    private function getQuery($postData, $siteByHandle)
    {
        $sortField = $postData['sortField'];
        $sortDirection = strtoupper($postData['sortDirection']);
        $filters = $postData['filters'];

        // query
        $filterConditions = ['and'];
        $notFoundRows = NotFoundRecord::find();

        // filters site
        if ($filters['site']) {
            $filterConditions[] = [
                'siteId' => $siteByHandle[$filters['site']]['id']
            ];
        }

        // filters url
        if ($filters['url']) {
            $filterConditions[] = [
                'like',
                'url',
                $filters['url'],
            ];
        }

        // filters referrer
        if ($filters['referrer']) {
            $filterConditions[] = [
                'like',
                'lastReferrer',
                $filters['referrer'],
            ];
        }

        // filters handled
        if ($filters['handled']) {
            $filterConditions[] = [
                'handled' => $filters['handled'] == 'yes' ? true : false,
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
        $notFoundRows = $notFoundRows->where($filterConditions);

        // sort order
        $notFoundRows = $notFoundRows
            ->orderBy($sortField . ' ' . $sortDirection . ', {{%redirector_not_found}}.id ' . $sortDirection);

        return $notFoundRows;
    }
}
