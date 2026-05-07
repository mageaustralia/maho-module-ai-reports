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

// Schedule + email columns
foreach ([
    'schedule_cron_expr'    => ['type' => Maho\Db\Ddl\Table::TYPE_VARCHAR, 'length' => 64,   'nullable' => true,  'comment' => 'Standard 5-field cron expr or null'],
    'schedule_enabled'      => ['type' => Maho\Db\Ddl\Table::TYPE_BOOLEAN,                   'nullable' => false, 'default' => 0,    'comment' => 'On/off without losing the cron expr'],
    'email_recipients'      => ['type' => Maho\Db\Ddl\Table::TYPE_VARCHAR, 'length' => 1024, 'nullable' => true,  'comment' => 'CSV of email addresses'],
    'email_subject_prefix'  => ['type' => Maho\Db\Ddl\Table::TYPE_VARCHAR, 'length' => 128,  'nullable' => true,  'comment' => 'Optional subject prefix override'],
    'last_scheduled_at'     => ['type' => Maho\Db\Ddl\Table::TYPE_DATETIME,                  'nullable' => true,  'comment' => 'Last cron run timestamp (distinct from manual last_run_at)'],
    'last_scheduled_status' => ['type' => Maho\Db\Ddl\Table::TYPE_VARCHAR, 'length' => 16,   'nullable' => true,  'comment' => 'success | error'],
    'last_scheduled_error'  => ['type' => Maho\Db\Ddl\Table::TYPE_VARCHAR, 'length' => 1024, 'nullable' => true,  'comment' => 'Error message from the last cron run, if any'],
] as $col => $spec) {
    $params = ['nullable' => $spec['nullable']];
    if (isset($spec['length']))  $params['length']  = $spec['length'];
    if (isset($spec['default'])) $params['default'] = $spec['default'];
    $params['comment'] = $spec['comment'];
    if (!$conn->tableColumnExists($table, $col)) {
        $conn->addColumn($table, $col, ['type' => $spec['type']] + $params);
    }
}

// Run-log table for cron + email audit trail
$logTable = $this->getTable('aireports/run_log');
if (!$conn->isTableExists($logTable)) {
    $tableObj = $conn->newTable($logTable)
        ->addColumn('log_id',       Maho\Db\Ddl\Table::TYPE_INTEGER, null,  ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('report_id',    Maho\Db\Ddl\Table::TYPE_INTEGER, null,  ['unsigned' => true, 'nullable' => false])
        ->addColumn('triggered_by', Maho\Db\Ddl\Table::TYPE_VARCHAR, 16,   ['nullable' => false])  // 'cron' | 'manual'
        ->addColumn('started_at',   Maho\Db\Ddl\Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addColumn('elapsed_ms',   Maho\Db\Ddl\Table::TYPE_INTEGER, null,  ['unsigned' => true, 'nullable' => true])
        ->addColumn('row_count',    Maho\Db\Ddl\Table::TYPE_INTEGER, null,  ['unsigned' => true, 'nullable' => true])
        ->addColumn('status',       Maho\Db\Ddl\Table::TYPE_VARCHAR, 16,   ['nullable' => false])  // 'success' | 'error'
        ->addColumn('error_message', Maho\Db\Ddl\Table::TYPE_VARCHAR, 1024, ['nullable' => true])
        ->addColumn('email_sent_to', Maho\Db\Ddl\Table::TYPE_VARCHAR, 1024, ['nullable' => true])  // populated for cron runs
        ->addIndex($this->getIdxName($logTable, ['report_id']), ['report_id'])
        ->addForeignKey(
            $this->getFkName($logTable, 'report_id', 'aireports/report', 'report_id'),
            'report_id',
            $table,
            'report_id',
            Maho\Db\Ddl\Table::ACTION_CASCADE,
            Maho\Db\Ddl\Table::ACTION_CASCADE,
        )
        ->setComment('AiReports - run-log audit trail (manual + cron)');
    $conn->createTable($tableObj);
}

$this->endSetup();
