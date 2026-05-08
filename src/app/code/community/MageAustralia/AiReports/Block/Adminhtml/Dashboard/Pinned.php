<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_Dashboard_Pinned extends Mage_Adminhtml_Block_Template
{
    /**
     * @return MageAustralia_AiReports_Model_Resource_Report_Collection
     */
    public function getPinnedReports()
    {
        return Mage::getResourceModel('aireports/report_collection')
            ->addFieldToFilter('is_pinned_to_dashboard', 1)
            ->setOrder('pinned_sort_order', 'ASC')
            ->setPageSize(6);
    }

    public function getRunUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/runSaved');
    }

    public function getViewUrl(int $id): string
    {
        return $this->getUrl('adminhtml/aireports/viewSaved', ['id' => $id]);
    }

    public function canSee(): bool
    {
        return (bool) Mage::getSingleton('admin/session')->isAllowed('aireports/run');
    }
}
