<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/** @var Mage_Core_Model_Resource_Setup $this */
$this->startSetup();

$conn = $this->getConnection();
$table = $this->getTable('aireports/report');

if (!$conn->isTableExists($table)) {
    $tableObj = $conn->newTable($table)
        ->addColumn('report_id', Maho\Db\Ddl\Table::TYPE_INTEGER, null, [
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ], 'Report ID')
        ->addColumn('title', Maho\Db\Ddl\Table::TYPE_VARCHAR, 255, ['nullable' => false])
        ->addColumn('query_plan_json', Maho\Db\Ddl\Table::TYPE_TEXT, null, ['nullable' => false])
        ->addColumn('render_hint_json', Maho\Db\Ddl\Table::TYPE_TEXT, null, ['nullable' => false])
        ->addColumn('created_by_user_id', Maho\Db\Ddl\Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('created_at', Maho\Db\Ddl\Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addColumn('updated_at', Maho\Db\Ddl\Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addColumn('last_run_at', Maho\Db\Ddl\Table::TYPE_DATETIME, null, ['nullable' => true])
        ->addColumn('last_run_elapsed_ms', Maho\Db\Ddl\Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addIndex($this->getIdxName($table, ['created_by_user_id']), ['created_by_user_id'])
        ->addForeignKey(
            $this->getFkName($table, 'created_by_user_id', 'admin/user', 'user_id'),
            'created_by_user_id',
            $this->getTable('admin/user'),
            'user_id',
            Maho\Db\Ddl\Table::ACTION_SET_NULL,
            Maho\Db\Ddl\Table::ACTION_CASCADE,
        )
        ->setComment('AI Reports - saved report definitions');

    $conn->createTable($tableObj);
}

$this->endSetup();
