<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_Saved_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('aireportsSavedGrid');
        $this->setDefaultSort('updated_at');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
    }

    protected function _prepareCollection()
    {
        $collection = Mage::getResourceModel('aireports/report_collection');
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $this->addColumn('title', [
            'header' => $this->__('Title'),
            'index'  => 'title',
            'type'   => 'text',
        ]);

        $this->addColumn('created_at', [
            'header' => $this->__('Created'),
            'index'  => 'created_at',
            'type'   => 'datetime',
            'width'  => '160px',
        ]);

        $this->addColumn('updated_at', [
            'header' => $this->__('Updated'),
            'index'  => 'updated_at',
            'type'   => 'datetime',
            'width'  => '160px',
        ]);

        $this->addColumn('last_run_at', [
            'header'  => $this->__('Last Run'),
            'index'   => 'last_run_at',
            'type'    => 'datetime',
            'width'   => '160px',
            'default' => '-',
        ]);

        $this->addColumn('is_pinned_to_dashboard', [
            'header'  => $this->__('Pinned'),
            'index'   => 'is_pinned_to_dashboard',
            'type'    => 'options',
            'options' => [0 => $this->__('No'), 1 => $this->__('Yes')],
            'width'   => '80px',
        ]);

        $this->addColumn('action', [
            'header'    => $this->__('Action'),
            'width'     => '80px',
            'type'      => 'action',
            'getter'    => 'getId',
            'actions'   => [[
                'caption' => $this->__('View'),
                'url'     => ['base' => '*/*/viewSaved'],
                'field'   => 'id',
            ]],
            'filter'    => false,
            'sortable'  => false,
            'is_system' => true,
            'index'     => 'action',
        ]);

        return parent::_prepareColumns();
    }

    public function getRowUrl($row): string
    {
        return $this->getUrl('*/*/viewSaved', ['id' => $row->getId()]);
    }

    public function getGridUrl(): string
    {
        return $this->getUrl('*/*/savedGrid', ['_current' => true]);
    }
}
