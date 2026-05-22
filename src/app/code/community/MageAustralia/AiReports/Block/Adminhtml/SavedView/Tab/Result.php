<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_SavedView_Tab_Result extends Mage_Adminhtml_Block_Widget implements Mage_Adminhtml_Block_Widget_Tab_Interface
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

    public function getRunUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/runSaved');
    }
    public function getExportUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/exportSavedCsv');
    }
    public function getRenameUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/rename');
    }
    public function getDeleteUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/delete');
    }
    public function getBackUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/saved');
    }
    public function getDrillUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/drill');
    }
    public function getPinUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/pin');
    }
    public function getUnpinUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/unpin');
    }

    /**
     * Returns true when the saved plan's primitive supports a period override
     * (i.e. has a `period` or `period_a` arg). Primitives like stock_vs_velocity
     * and low_stock use only `lookback_days` and do not benefit from date inputs.
     */
    public function planSupportsPeriodOverride(): bool
    {
        $report = $this->getReport();
        if (!$report) {
            return false;
        }
        $plan = $report->getQueryPlan();
        $args = $plan['args'] ?? [];
        return isset($args['period']) || isset($args['period_a']);
    }

    #[\Override]
    public function getTabLabel(): string
    {
        return $this->__('Result');
    }
    #[\Override]
    public function getTabTitle(): string
    {
        return $this->__('Report result');
    }
    #[\Override]
    public function canShowTab(): bool
    {
        return true;
    }
    #[\Override]
    public function isHidden(): bool
    {
        return false;
    }
}
