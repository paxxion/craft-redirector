<?php

namespace paxxion\craftredirector\records;

use craft\db\ActiveRecord;
use craft\records\Site;

class NotFoundRecord extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%redirector_not_found}}';
    }

    public function getSite()
    {
        return $this->hasOne(Site::class, ['id' => 'siteId']);
    }
}
