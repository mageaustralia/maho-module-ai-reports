<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_SavedView_Tabs extends Mage_Adminhtml_Block_Widget_Tabs
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('aireportsSavedViewTabs');
        $this->setDestElementId('aireports_savedview_tabs');
        $this->setTitle('Saved Report');
    }

    #[\Override]
    protected function _beforeToHtml()
    {
        $this->addTab('result', [
            'label'   => $this->__('Result'),
            'title'   => $this->__('Report result'),
            'content' => $this->getLayout()->createBlock('aireports/adminhtml_savedView_tab_result')->toHtml(),
            'active'  => true,
        ]);

        $this->addTab('schedule', [
            'label'   => $this->__('Schedule & Email'),
            'title'   => $this->__('Schedule and email delivery'),
            'content' => $this->getLayout()->createBlock('aireports/adminhtml_savedView_tab_schedule')->toHtml(),
        ]);

        $this->addTab('history', [
            'label'   => $this->__('Run History'),
            'title'   => $this->__('Run history'),
            'content' => $this->getLayout()->createBlock('aireports/adminhtml_savedView_tab_runLogContainer')->toHtml(),
        ]);

        return parent::_beforeToHtml();
    }
}
