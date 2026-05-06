<?php

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_SavedGrid extends Mage_Adminhtml_Block_Template
{
    public function getRunUrl(): string  { return $this->getUrl('adminhtml/aireports/runSaved'); }
    public function getRenameUrl(): string { return $this->getUrl('adminhtml/aireports/rename'); }
    public function getDeleteUrl(): string { return $this->getUrl('adminhtml/aireports/delete'); }

    public function canManage(): bool
    {
        return (bool) Mage::getSingleton('admin/session')->isAllowed('aireports/manage_saved');
    }

    /** @return MageAustralia_AiReports_Model_Resource_Report_Collection */
    public function getReports()
    {
        return Mage::getResourceModel('aireports/report_collection')->setOrder('updated_at', 'DESC');
    }
}
