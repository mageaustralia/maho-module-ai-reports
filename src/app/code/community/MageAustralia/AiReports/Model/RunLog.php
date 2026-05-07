<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_RunLog extends Mage_Core_Model_Abstract
{
    public const TRIGGERED_BY_CRON   = 'cron';
    public const TRIGGERED_BY_MANUAL = 'manual';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR   = 'error';

    protected function _construct()
    {
        $this->_init('aireports/run_log');
    }

    /**
     * Create and save a run-log entry in a single call.
     *
     * @param int    $reportId
     * @param string $triggeredBy  TRIGGERED_BY_* constant
     * @param float  $startTime    microtime(true) value at run start
     * @param int    $elapsedMs
     * @param int    $rowCount
     * @param string $status       STATUS_* constant
     * @param string|null $errorMessage
     * @param string|null $emailSentTo  CSV of addresses, or null
     * @return self
     */
    public static function record(
        int $reportId,
        string $triggeredBy,
        float $startTime,
        int $elapsedMs,
        int $rowCount,
        string $status,
        ?string $errorMessage,
        ?string $emailSentTo,
    ): self {
        $started = (new \DateTimeImmutable('@' . (int) $startTime))->format('Y-m-d H:i:s');

        /** @var self $log */
        $log = Mage::getModel('aireports/run_log');
        $log->setData([
            'report_id'     => $reportId,
            'triggered_by'  => $triggeredBy,
            'started_at'    => $started,
            'elapsed_ms'    => $elapsedMs,
            'row_count'     => $rowCount,
            'status'        => $status,
            'error_message' => $errorMessage !== null ? substr($errorMessage, 0, 1000) : null,
            'email_sent_to' => $emailSentTo,
        ]);
        $log->save();
        return $log;
    }
}
