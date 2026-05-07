<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_SavedView_Tab_RunLog extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('aireportsRunLogGrid');
        $this->setDefaultSort('started_at');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
        $this->setPagerVisibility(true);
        $this->setFilterVisibility(true);
    }

    #[\Override]
    protected function _prepareCollection()
    {
        $reportId = (int) Mage::registry('aireports_current_report')?->getId();
        /** @var MageAustralia_AiReports_Model_Resource_RunLog_Collection $collection */
        $collection = Mage::getResourceModel('aireports/run_log_collection')
            ->addFieldToFilter('report_id', $reportId);
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    #[\Override]
    protected function _prepareColumns()
    {
        $this->addColumn('started_at', [
            'header' => $this->__('Started'),
            'index'  => 'started_at',
            'type'   => 'datetime',
            'width'  => '160px',
        ]);

        $this->addColumn('triggered_by', [
            'header'  => $this->__('Trigger'),
            'index'   => 'triggered_by',
            'type'    => 'options',
            'options' => ['cron' => 'Cron', 'manual' => 'Manual'],
            'width'   => '90px',
        ]);

        $this->addColumn('status', [
            'header'         => $this->__('Status'),
            'index'          => 'status',
            'type'           => 'options',
            'options'        => ['success' => 'Success', 'error' => 'Error'],
            'frame_callback' => [$this, 'decorateStatus'],
            'width'          => '90px',
        ]);

        $this->addColumn('elapsed_ms', [
            'header' => $this->__('Elapsed (ms)'),
            'index'  => 'elapsed_ms',
            'type'   => 'number',
            'width'  => '110px',
        ]);

        $this->addColumn('row_count', [
            'header' => $this->__('Rows'),
            'index'  => 'row_count',
            'type'   => 'number',
            'width'  => '70px',
        ]);

        $this->addColumn('email_sent_to', [
            'header' => $this->__('Emailed To'),
            'index'  => 'email_sent_to',
            'type'   => 'text',
        ]);

        $this->addColumn('error_message', [
            'header'   => $this->__('Error'),
            'index'    => 'error_message',
            'type'     => 'text',
            'truncate' => 80,
        ]);

        return parent::_prepareColumns();
    }

    public function decorateStatus(mixed $value, mixed $row, mixed $column, bool $isExport): string
    {
        if ($isExport) {
            return (string) $value;
        }
        $class = $value === 'success' ? 'grid-severity-notice' : 'grid-severity-major';
        return '<span class="' . $class . '"><span>' . htmlspecialchars((string) $value, ENT_QUOTES) . '</span></span>';
    }

    #[\Override]
    public function getRowUrl($row): string
    {
        return '';
    }

    #[\Override]
    public function getGridUrl(): string
    {
        return $this->getUrl('*/*/runlog', ['_current' => true, 'id' => Mage::registry('aireports_current_report')?->getId()]);
    }
}
