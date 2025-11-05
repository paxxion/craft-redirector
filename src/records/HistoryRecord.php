<?php

namespace paxxion\craftredirector\records;

use craft\db\ActiveRecord;

class HistoryRecord extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%redirector_history}}';
    }
}
