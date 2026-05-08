<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/** @var Mage_Core_Model_Resource_Setup $this */
$this->startSetup();
$conn  = $this->getConnection();
$table = $this->getTable('aireports/report');

if (!$conn->tableColumnExists($table, 'is_pinned_to_dashboard')) {
    $conn->addColumn($table, 'is_pinned_to_dashboard', [
        'type'     => Maho\Db\Ddl\Table::TYPE_BOOLEAN,
        'nullable' => false,
        'default'  => 0,
        'comment'  => 'Whether this report appears on the admin dashboard',
    ]);
}

if (!$conn->tableColumnExists($table, 'pinned_sort_order')) {
    $conn->addColumn($table, 'pinned_sort_order', [
        'type'     => Maho\Db\Ddl\Table::TYPE_INTEGER,
        'unsigned' => true,
        'nullable' => false,
        'default'  => 0,
        'comment'  => 'Display order among pinned reports (ascending)',
    ]);
}

$this->endSetup();
