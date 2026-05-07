<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_SavedView_Tab_Result
    extends Mage_Adminhtml_Block_Widget
    implements Mage_Adminhtml_Block_Widget_Tab_Interface
{
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('mageaustralia/aireports/savedview/tab_result.phtml');
    }

    public function getReport(): ?MageAustralia_AiReports_Model_Report
    {
        return Mage::registry('aireports_current_report');
    }

    public function getRunUrl(): string    { return $this->getUrl('adminhtml/aireports/runSaved'); }
    public function getExportUrl(): string { return $this->getUrl('adminhtml/aireports/exportSavedCsv'); }
    public function getRenameUrl(): string { return $this->getUrl('adminhtml/aireports/rename'); }
    public function getDeleteUrl(): string { return $this->getUrl('adminhtml/aireports/delete'); }
    public function getBackUrl(): string   { return $this->getUrl('adminhtml/aireports/saved'); }

    public function getTabLabel(): string  { return $this->__('Result'); }
    public function getTabTitle(): string  { return $this->__('Report result'); }
    public function canShowTab(): bool     { return true; }
    public function isHidden(): bool       { return false; }
}
