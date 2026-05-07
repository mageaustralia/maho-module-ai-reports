<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_Saved extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'aireports';
        $this->_controller = 'adminhtml_saved';
        $this->_headerText = $this->__('Saved Reports');
        parent::__construct();
        // Reports are created via the Ask page, not by clicking Add - hide the Add button.
        $this->_removeButton('add');
    }
}
