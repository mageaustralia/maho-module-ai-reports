<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_SavedGrid extends Mage_Adminhtml_Block_Template
{
    public function getViewUrl(int $reportId): string { return $this->getUrl('adminhtml/aireports/viewSaved', ['id' => $reportId]); }
    public function getRunUrl(): string        { return $this->getUrl('adminhtml/aireports/runSaved'); }
    public function getRenameUrl(): string     { return $this->getUrl('adminhtml/aireports/rename'); }
    public function getDeleteUrl(): string     { return $this->getUrl('adminhtml/aireports/delete'); }
    public function getExportSavedUrl(): string { return $this->getUrl('adminhtml/aireports/exportSavedCsv'); }

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
