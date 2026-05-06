<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_SavedView extends Mage_Adminhtml_Block_Template
{
    public function getReport(): MageAustralia_AiReports_Model_Report
    {
        return Mage::registry('aireports_current_report');
    }

    public function getBackUrl(): string   { return $this->getUrl('adminhtml/aireports/saved'); }
    public function getRunUrl(): string    { return $this->getUrl('adminhtml/aireports/runSaved'); }
    public function getExportUrl(): string { return $this->getUrl('adminhtml/aireports/exportSavedCsv'); }
    public function getRenameUrl(): string { return $this->getUrl('adminhtml/aireports/rename'); }
    public function getDeleteUrl(): string { return $this->getUrl('adminhtml/aireports/delete'); }

    public function canManage(): bool
    {
        return (bool) Mage::getSingleton('admin/session')->isAllowed('aireports/manage_saved');
    }
}
