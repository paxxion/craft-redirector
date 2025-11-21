<?php

namespace paxxion\craftredirector\services;

use Carbon\Carbon;
use Craft;
use craft\base\Component;
use Exception;
use paxxion\craftredirector\records\NotFoundRecord;
use paxxion\craftredirector\records\RedirectRecord;
use paxxion\craftredirector\Redirector;
use Twig\Error\RuntimeError;
use yii\db\Expression;
use yii\web\HttpException;

class RedirectService extends Component
{
    public static function handleException($event)
    {
        try
        {
            $plugin = Redirector::getInstance();
            $settings = $plugin->getSettings();

            $exception = $event->exception;

            // if runtime error exception retrieve previous exception (example twig template fired 404)
            if ($exception instanceof RuntimeError) {
                $exception = $exception->getPrevious() ?? null;
            }

            // if exception status code is not 404 then return
            if ($exception instanceof HttpException && $exception->statusCode !== 404) {
                return;
            }

            // request
            $request = Craft::$app->getRequest();
            if (!$request->isSiteRequest) {
                return;
            }

            // requested url
            $currentSite = Craft::$app->getSites()->getCurrentSite();
            $currentSiteBaseUrl = trim($currentSite->getBaseUrl(), '/');
            $currentSitePath = parse_url($currentSiteBaseUrl, PHP_URL_PATH);
            
            $referrer = $request->getReferrer();
            if ($referrer && str_contains($referrer, '/admin/redirector/')) {
                $referrer = null;
            }
            
            $absoluteUrl = $request->getAbsoluteUrl();            
            $requestedUrlData = [
                'absoluteUrl' => $absoluteUrl,
                'scheme' => parse_url($absoluteUrl, PHP_URL_SCHEME),
                'host' => parse_url($absoluteUrl, PHP_URL_HOST),
                'path' => '/' . trim(parse_url($absoluteUrl, PHP_URL_PATH), '/'),
                'query' => parse_url($absoluteUrl, PHP_URL_QUERY),
                'referrer' => $referrer,
            ];

            // not found row
            $notFoundRow = NotFoundRecord::find()
                ->where([
                    'siteId' => $currentSite->id,
                    'url' => $requestedUrlData['path'],
                ])
                ->one();
            
            // search for exact match redirect for current site id
            // the oldUrl must equal the request url path
            $requestedUrlPath = $requestedUrlData['path'];
            $requestedUrlPathNoSite = $requestedUrlData['path'];
            $requestedUrlQuery = $requestedUrlData['query'];
            if ($currentSitePath && str_starts_with($requestedUrlPathNoSite, $currentSitePath)) {
                $requestedUrlPathNoSite = '/' . trim(str_replace($currentSitePath, '', $requestedUrlPathNoSite), '/');
            }
            $redirect = RedirectRecord::find()
                ->where([
                    'enabled' => true,
                    'siteId' => $currentSite->id,
                    'matchType' => 'exact',
                ])
                ->andWhere(['in', 'oldUrl', [
                    $requestedUrlPath,
                    $requestedUrlPathNoSite,
                    $requestedUrlPath . ($requestedUrlQuery ? '?' . $requestedUrlQuery : ''),
                    $requestedUrlPathNoSite . ($requestedUrlQuery ? '?' . $requestedUrlQuery : ''),
                ]])
                ->orderBy(new Expression('CHAR_LENGTH([[oldUrl]]) DESC, [[id]] DESC'))
                ->one();
            if (!is_null($redirect)) {
                self::setHandledNotFoundRow($notFoundRow);
                self::increaseHits($redirect);
                self::redirectTo($exception, $redirect->newUrl, $redirect->httpCode);
            }

            // search for exact match redirect globally
            // the oldUrl must equal the request url path (without the sitePath if present)
            $requestedUrlPath = $requestedUrlData['path'];
            $requestedUrlPathNoSite = $requestedUrlData['path'];
            $requestedUrlQuery = $requestedUrlData['query'];
            if ($currentSitePath && str_starts_with($requestedUrlPathNoSite, $currentSitePath)) {
                $requestedUrlPathNoSite = '/' . trim(str_replace($currentSitePath, '', $requestedUrlPathNoSite), '/');
            }
            $redirect = RedirectRecord::find()
                ->where([
                    'enabled' => true,
                    'siteId' => NULL,
                    'matchType' => 'exact',
                ])
                ->andWhere(['in', 'oldUrl', [
                    $requestedUrlPath,
                    $requestedUrlPathNoSite,
                    $requestedUrlPath . ($requestedUrlQuery ? '?' . $requestedUrlQuery : ''),
                    $requestedUrlPathNoSite . ($requestedUrlQuery ? '?' . $requestedUrlQuery : ''),
                ]])
                ->orderBy(new Expression('CHAR_LENGTH([[oldUrl]]) DESC, [[id]] DESC'))
                ->one();
            if (!is_null($redirect)) {
                self::setHandledNotFoundRow($notFoundRow);
                self::increaseHits($redirect);
                self::redirectTo($exception, $redirect->newUrl, $redirect->httpCode);
            }

            // regex match redirects
            $requestedUrlPath = $requestedUrlData['path'];
            $requestedUrlQuery = $requestedUrlData['query'];
            $requestedFullUrl = $requestedUrlPath . ($requestedUrlQuery ? '?' . $requestedUrlQuery : '');
            $redirects = RedirectRecord::find()
                ->where([
                    'enabled' => true,
                    'matchType' => 'regex'
                ])
                ->andWhere([
                    'or',
                    ['siteId' => $currentSite->id],
                    ['siteId' => NULL],
                ])
                ->orderBy(new Expression(
                    'CASE WHEN [[siteId]] = :sid THEN 1 ELSE 0 END DESC, [[priority]] DESC, [[id]] DESC'
                ))
                ->addParams([':sid' => $currentSite->id])
                ->all();
                        
            foreach ($redirects as $redirect) {
                $pattern = '`' . $redirect->oldUrl . '`i';
                $redirectUrl = $redirect->newUrl;
                if (preg_match($pattern, $requestedFullUrl, $matches)) {
                    if (count($matches) > 0) {
                        for ($i=1; $i < count($matches); $i++) {
                            $redirectUrl = str_replace('$' . $i, $matches[$i], $redirectUrl);                            
                        }
                    }

                    self::setHandledNotFoundRow($notFoundRow);
                    self::increaseHits($redirect);
                    self::redirectTo($exception, $redirectUrl, $redirect->httpCode);
                }            
            }

            // not found exclusions
            $excluded = false;
            $requestedUrlPath = $requestedUrlData['path'];

            if ($settings->excludedNotFound) {
                foreach ($settings->excludedNotFound as $excludedNotFound) {
                    if ($requestedUrlPath == $excludedNotFound['path']) {
                        $excluded = true;
                        break;
                    }
                }
            }

            if ($excluded) {
                return;
            }

            if ($settings->excludedNotFoundPatterns) {
                foreach ($settings->excludedNotFoundPatterns as $excludedNotFoundPattern) {
                    $pattern = '`' . $excludedNotFoundPattern['pattern'] . '`i';
                    if (preg_match($pattern, $absoluteUrl)) {
                        $excluded = true;
                        break;
                    }
                }
            }
            
            if ($excluded) {
                return;
            }

            // not found save to database            
            if (is_null($notFoundRow)) {
                $notFoundRow = new NotFoundRecord();
                $notFoundRow->siteId = $currentSite['id'];
                $notFoundRow->lastReferrer = '';
                $notFoundRow->url = $requestedUrlData['path'];
            }
            
            $notFoundRow->handled = false;
            if ($requestedUrlData['referrer']) {
                $notFoundRow->lastReferrer = $requestedUrlData['referrer'];
            }
            $notFoundRow->totalHits += 1;
            $notFoundRow->dateLastHit = Carbon::now();
            $notFoundRow->save();
        }
        catch (\Throwable $ex) {
            Craft::error('Redirector - error handling redirect: ' . $ex->getMessage());

            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $ex;
            }
        }
    }

    public static function setHandledNotFoundRow($notFoundRow)
    {
        try
        {
            if (is_null($notFoundRow)) return;

            $notFoundRow->handled = true;
            $notFoundRow->save();
        }
        catch (\Throwable $ex) {
            Craft::error('Redirector - error setting handled on not found row: ' . $ex->getMessage());

            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $ex;
            }
        }
    }

    public static function redirectTo($exception, $redirectUrl, $redirectHttpCode)
    {
        try
        {
            $response = Craft::$app->getResponse();
            $response->setNoCacheHeaders();

            // if 410 use the current $exception and change its' status code to 410 so the correct template will be used
            if ($redirectHttpCode == 410) {
                $errorHandler = Craft::$app->getErrorHandler();
                $errorHandler->exception = $exception;
                $errorHandler->exception->statusCode = $redirectHttpCode;
                
                $response = Craft::$app->runAction('templates/render-error');
                $response->setStatusCode($redirectHttpCode);
                $response->send();
                
                Craft::$app->end();
            }

            $currentSite = Craft::$app->getSites()->getCurrentSite();

            $redirectUrl = '/' . trim($redirectUrl, '/');

            $redirectSite = null;
            $sites = Craft::$app->getSites()->getAllSites();
            foreach ($sites as $site) {
                $siteBaseUrl = trim($site->getBaseUrl(), '/');
                $sitePath = parse_url($siteBaseUrl, PHP_URL_PATH);

                if ($sitePath && str_starts_with($redirectUrl, $sitePath)) {
                    $redirectSite = $site;
                    $redirectUrl = '/' . trim(str_replace($sitePath, '', $redirectUrl), '/');
                    break;
                }
            }

            if (is_null($redirectSite)) {
                $redirectSite = $currentSite;
            }

            $redirectSiteBaseUrl = trim($redirectSite->getBaseUrl(), '/');
            $redirectUrl = $redirectSiteBaseUrl . $redirectUrl;
            
            
            $response->redirect($redirectUrl, $redirectHttpCode)->send();
            Craft::$app->end();
        }
        catch (\Throwable $ex) {
            Craft::error('Redirector - error redirecting exception: ' . $ex->getMessage());

            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $ex;
            }
        }
    }

    public static function increaseHits($redirect) {
        try
        {
            $redirect->totalHits += 1;
            $redirect->dateLastHit = Carbon::now();
            $redirect->save();
        }
        catch (\Throwable $ex) {
            Craft::error('Redirector - error increasing hits: ' . $ex->getMessage());

            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $ex;
            }
        }
    }
}
