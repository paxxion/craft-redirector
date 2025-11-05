<?php

namespace paxxion\craftredirector\services\cp;

use craft\base\Component;
use paxxion\craftredirector\records\NotFoundRecord;

class AuditService extends Component
{
    public static function getNotHandledCount()
    {
        return NotFoundRecord::find()
            ->where(['handled' => false])
            ->count();
    }
}
