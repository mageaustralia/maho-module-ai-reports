<?php

/** @var Mage_Core_Model_Resource_Setup $this */
$this->startSetup();

$conn = $this->getConnection();
$table = $this->getTable('aireports/report');

if (!$conn->isTableExists($table)) {
    $tableObj = $conn->newTable($table)
        ->addColumn('report_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ], 'Report ID')
        ->addColumn('title', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, ['nullable' => false])
        ->addColumn('query_plan_json', Varien_Db_Ddl_Table::TYPE_TEXT, null, ['nullable' => false])
        ->addColumn('render_hint_json', Varien_Db_Ddl_Table::TYPE_TEXT, null, ['nullable' => false])
        ->addColumn('created_by_user_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addColumn('last_run_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => true])
        ->addColumn('last_run_elapsed_ms', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addIndex($this->getIdxName($table, ['created_by_user_id']), ['created_by_user_id'])
        ->addForeignKey(
            $this->getFkName($table, 'created_by_user_id', 'admin/user', 'user_id'),
            'created_by_user_id',
            $this->getTable('admin/user'),
            'user_id',
            Varien_Db_Ddl_Table::ACTION_SET_NULL,
            Varien_Db_Ddl_Table::ACTION_CASCADE,
        )
        ->setComment('AI Reports - saved report definitions');

    $conn->createTable($tableObj);
}

$this->endSetup();
