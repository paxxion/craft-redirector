<?php

namespace paxxion\craftredirector\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\helpers\ElementHelper;
use paxxion\craftredirector\records\HistoryRecord;
use paxxion\craftredirector\records\RedirectRecord;
use paxxion\craftredirector\records\StashRecord;

class StashService extends Component
{
    public static function stash(ElementEvent $event)
    {
        try
        {
            $element = $event->element;
            if (!$element instanceof Entry) return;
            if (ElementHelper::isDraftOrRevision($element) || $element->propagating) return;
            
            $allUrls = self::getAllUrls($element);
            foreach ($allUrls as $siteId => $url) {
                $stash = StashRecord::find()
                    ->where([
                        'siteId' => $siteId,
                        'entryId' => $element->id,
                    ])
                    ->one();
                
                if (is_null($stash)) {
                    $stash = new StashRecord();
                    $stash->siteId = $siteId;
                    $stash->entryId = $element->id;
                }

                $stash->entryOldUrl = $url;
                $stash->save();
            }
        }
        catch (\Throwable $ex) {
            Craft::error('Redirector - error stashing current url: ' . $ex->getMessage());

            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $ex;
            }
        }
    }

    public static function save(ElementEvent $event)
    {
        try
        {
            $element = $event->element;
            if (!$element instanceof Entry) return;
            if (ElementHelper::isDraftOrRevision($element) || $element->propagating) return;

            $allNewUrls = self::getAllUrls($element);
            foreach ($allNewUrls as $siteId => $newUrl) {
                $stash = StashRecord::find()
                    ->where([
                        'siteId' => $siteId,
                        'entryId' => $element->id,
                    ])
                    ->one();
            
                // continue only if new url changed
                if ($stash && $stash->entryOldUrl !== $newUrl) {
                    // create or update redirect
                    $redirect = RedirectRecord::find()
                        ->where([
                            'siteId' => $siteId,
                            'entryId' => $element->id,
                            'oldUrl' => $stash->entryOldUrl,
                        ])
                        ->one();

                    if (is_null($redirect)) {
                        $redirect = new RedirectRecord();
                        $redirect->enabled = true;
                        $redirect->siteId = $siteId;
                        $redirect->entryId = $element->id;
                        $redirect->oldUrl = $stash->entryOldUrl;
                        $redirect->matchType = 'exact';
                        $redirect->httpCode = 301;
                    }

                    $redirect->newUrl = $newUrl;
                    $redirect->save();

                    // update every redirects for this entry so it points to new url (to avoid cascading redirects)
                    RedirectRecord::updateAll(
                        ['newUrl' => $newUrl],
                        [
                            'siteId' => $siteId,
                            'entryId' => $element->id,
                        ]
                    );

                    // save previous url in history
                    $history = new HistoryRecord();
                    $history->siteId = $siteId;
                    $history->entryId = $element->id;
                    $history->entryUrl = $stash->entryOldUrl;
                    $history->save();
                }
            }
        }
        catch (\Throwable $ex) {
            Craft::error('Redirector - error saving redirect: ' . $ex->getMessage());

            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $ex;
            }
        }
    }

    private static function getAllUrls($element)
    {
        $urls = [];

        $sites = Craft::$app->getSites()->getAllSites();
        foreach ($sites as $site) {
            $entry = Entry::find()->id($element->id)->siteId($site->id)->one();
            if ($entry && $entry->getUrl()) {
                $urls[$site->id] = '/' . trim(parse_url($entry->getUrl(), PHP_URL_PATH), '/');
            }
        }

        return $urls;
    }
}
