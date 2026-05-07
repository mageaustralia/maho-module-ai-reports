<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_SavedView_Tab_Schedule
    extends Mage_Adminhtml_Block_Widget
    implements Mage_Adminhtml_Block_Widget_Tab_Interface
{
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('mageaustralia/aireports/savedview/tab_schedule.phtml');
    }

    public function getReport(): ?MageAustralia_AiReports_Model_Report
    {
        return Mage::registry('aireports_current_report');
    }

    public function getScheduleUrl(): string { return $this->getUrl('adminhtml/aireports/schedule'); }

    public function getTabLabel(): string  { return $this->__('Schedule & Email'); }
    public function getTabTitle(): string  { return $this->__('Schedule and email delivery'); }
    public function canShowTab(): bool     { return (bool) Mage::getSingleton('admin/session')->isAllowed('aireports/manage_saved'); }
    public function isHidden(): bool       { return false; }
}
