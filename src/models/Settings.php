<?php

namespace paxxion\craftredirector\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Redirector settings
 */
class Settings extends Model
{
    public $excludedNotFound = [];
    public $excludedNotFoundPatterns = [];

    public $openAiApiKey = '';

    public function getOpenAiApiKey()
    {
        $expanded = App::parseEnv($this->openAiApiKey);
        $resolved = Craft::getAlias($expanded);

        return $resolved;
    }
}
