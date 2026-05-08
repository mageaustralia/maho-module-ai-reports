<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_SavedView extends Mage_Adminhtml_Block_Widget_Container
{
    public function __construct()
    {
        parent::__construct();

        $this->setTemplate('mageaustralia/aireports/saved_view_container.phtml');

        $this->_addButton('back', [
            'label'   => $this->__('Back'),
            'onclick' => "setLocation('" . $this->getBackUrl() . "')",
            'class'   => 'back',
        ]);

        $this->_addButton('rerun', [
            'label'   => $this->__('Re-run'),
            'onclick' => 'aireportsSavedView.rerun()',
            'class'   => 'save',
        ]);

        $this->_addButton('export', [
            'label'   => $this->__('Export CSV'),
            'onclick' => 'aireportsSavedView.exportCsv()',
        ]);

        if ($this->canManage()) {
            $this->_addButton('rename', [
                'label'   => $this->__('Rename'),
                'onclick' => 'aireportsSavedView.rename()',
            ]);

            $this->_addButton('delete', [
                'label'   => $this->__('Delete'),
                'onclick' => 'aireportsSavedView.deleteReport()',
                'class'   => 'delete',
            ]);
        }
    }

    public function getReport(): ?MageAustralia_AiReports_Model_Report
    {
        return Mage::registry('aireports_current_report');
    }

    #[\Override]
    public function getHeaderText()
    {
        $report = $this->getReport();
        return $report ? $this->escapeHtml($report->getTitle()) : $this->__('Saved Report');
    }

    public function getBackUrl(): string     { return $this->getUrl('adminhtml/aireports/saved'); }
    public function getRenameUrl(): string   { return $this->getUrl('adminhtml/aireports/rename'); }
    public function getDeleteUrl(): string   { return $this->getUrl('adminhtml/aireports/delete'); }

    public function canManage(): bool
    {
        return (bool) Mage::getSingleton('admin/session')->isAllowed('aireports/manage_saved');
    }
}
