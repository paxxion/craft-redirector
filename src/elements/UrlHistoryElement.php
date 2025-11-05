<?php

namespace paxxion\craftredirector\elements;

use Carbon\Carbon;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\BaseUiElement;
use paxxion\craftredirector\records\HistoryRecord;

class UrlHistoryElement extends BaseUiElement
{
    public function selectorLabel(): string
    {
        return Craft::t('pxx-redirector', 'Url History');
    }

    public function formHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        $rows = null;
        if (!is_null($element) && $element instanceof Entry) {
            $site = Craft::$app->sites->getSiteById($element->siteId);
            $components = parse_url($site->getBaseUrl());
            $baseUrl = $components['scheme'] . '://' . $components['host'];
            
            $initialDate = $element->dateCreated;
            $dbRows = HistoryRecord::find()
                ->where([
                    'siteId' => $element->siteId,
                    'entryId' => $element->id,
                ])
                ->orderBy([ 'dateCreated' => SORT_ASC ])
                ->all();

            $rows = [];
            $prevRowDate = null;

            foreach ($dbRows as $dbRow) {
                $dateFrom = !is_null($prevRowDate)
                    ? $prevRowDate
                    : $initialDate;
                $dateTo = Carbon::parseFromLocale($dbRow->dateCreated, null, "UTC");

                $rows[] = [
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'entryUrl' => $baseUrl . $dbRow->entryUrl,
                ];

                $prevRowDate = $dateTo;
            }
        }

        return Craft::$app->getView()->renderTemplate('pxx-redirector/cp/elements/urlHistory', [
            'rows' => $rows,
        ]);
    }

    protected function selectorIcon(): ?string
    {
        return 'clock-rotate-left';
    }
}
