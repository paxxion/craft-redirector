<?php

namespace paxxion\craftredirector\records;

use craft\db\ActiveRecord;

class StashRecord extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%redirector_stash}}';
    }
}
