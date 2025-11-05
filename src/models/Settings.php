<?php

namespace paxxion\craftredirector\models;

use Craft;
use craft\base\Model;

/**
 * Redirector settings
 */
class Settings extends Model
{
    public $excludedNotFound = [];
    public $excludedNotFoundPatterns = [];
}
