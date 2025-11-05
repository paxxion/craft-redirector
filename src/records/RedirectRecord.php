<?php

namespace paxxion\craftredirector\records;

use craft\db\ActiveRecord;
use craft\records\Site;

class RedirectRecord extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%redirector_redirects}}';
    }

    public function getSite()
    {
        return $this->hasOne(Site::class, ['id' => 'siteId']);
    }
}
