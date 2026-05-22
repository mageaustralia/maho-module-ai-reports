<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_SavedView_Tab_RunLogContainer extends Mage_Adminhtml_Block_Widget implements Mage_Adminhtml_Block_Widget_Tab_Interface
{
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('mageaustralia/aireports/savedview/tab_runlog.phtml');
    }

    #[\Override]
    public function getTabLabel(): string
    {
        return $this->__('Run History');
    }
    #[\Override]
    public function getTabTitle(): string
    {
        return $this->__('Run history');
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
