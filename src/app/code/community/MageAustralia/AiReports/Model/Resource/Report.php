<?php

declare(strict_types=1);

class MageAustralia_AiReports_Model_Resource_Report extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct()
    {
        $this->_init('aireports/report', 'report_id');
    }
}
