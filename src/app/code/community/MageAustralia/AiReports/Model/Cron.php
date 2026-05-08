<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_Cron
{
    /**
     * Iterate enabled saved reports; for each, evaluate its cron_expr against
     * "now". If it matches and we have not already run this report in the
     * current minute, execute it, write to run_log, optionally email the
     * result, and update last_scheduled_* fields.
     */
    #[\Maho\Config\CronJob('aireports_run_scheduled', schedule: '* * * * *')]
    public function runScheduled(Mage_Cron_Model_Schedule $schedule): void
    {
        $now = new \DateTimeImmutable('now');

        /** @var MageAustralia_AiReports_Model_Resource_Report_Collection $reports */
        $reports = Mage::getResourceModel('aireports/report_collection')
            ->addFieldToFilter('schedule_enabled', 1)
            ->addFieldToFilter('schedule_cron_expr', ['notnull' => true])
            ->addFieldToFilter('schedule_cron_expr', ['neq' => '']);

        foreach ($reports as $report) {
            if (!$this->isDue($report, $now)) {
                continue;
            }
            $this->runOne($report, $now);
        }
    }

    /**
     * Returns true if the report's cron expression matches $now AND the report
     * has not already been run in the current minute window.
     */
    private function isDue(MageAustralia_AiReports_Model_Report $report, \DateTimeImmutable $now): bool
    {
        $expr = (string) $report->getData('schedule_cron_expr');
        if ($expr === '') {
            return false;
        }

        if (!MageAustralia_AiReports_Model_CronExpressionMatcher::matches($expr, $now)) {
            return false;
        }

        // Guard: do not run again if already executed in this minute.
        $lastRan = (string) $report->getData('last_scheduled_at');
        if ($lastRan !== '') {
            try {
                $lastDt = new \DateTimeImmutable($lastRan);
                // Same calendar minute = already ran.
                if ($lastDt->format('Y-m-d H:i') === $now->format('Y-m-d H:i')) {
                    return false;
                }
            } catch (\Throwable) {
                // Malformed timestamp - allow run.
            }
        }

        return true;
    }

    private function runOne(MageAustralia_AiReports_Model_Report $report, \DateTimeImmutable $now): void
    {
        $start = microtime(true);
        try {
            /** @var MageAustralia_AiReports_Helper_Data $helper */
            $helper = Mage::helper('aireports');

            // Use all available store IDs for cron runs (no session-based restriction).
            $stores = [];
            foreach (Mage::app()->getStores(false) as $store) {
                $stores[] = (int) $store->getId();
            }
            if (empty($stores)) {
                $stores = [0];
            }

            $validator = new MageAustralia_AiReports_Model_QueryPlanValidator($helper->getRegistry());
            $valid     = $validator->validate($report->getQueryPlan(), $stores);

            $executor  = new MageAustralia_AiReports_Model_PrimitiveExecutor(
                $helper->getRegistry(),
                new MageAustralia_AiReports_Model_RenderEnvelopeBuilder(),
                null, // No PII masking in cron context
            );
            $envelope  = $executor->run($valid['plan'], $valid['effectiveStoreIds'], $valid['scopeWarning']);
            $elapsed   = (int) ((microtime(true) - $start) * 1000);

            $emailedTo = $this->maybeEmail($report, $envelope);

            $report->setData('last_scheduled_at', Mage_Core_Model_Locale::nowUtc());
            $report->setData('last_scheduled_status', MageAustralia_AiReports_Model_RunLog::STATUS_SUCCESS);
            $report->setData('last_scheduled_error', null);
            $report->save();

            MageAustralia_AiReports_Model_RunLog::record(
                reportId:     (int) $report->getId(),
                triggeredBy:  MageAustralia_AiReports_Model_RunLog::TRIGGERED_BY_CRON,
                startTime:    $start,
                elapsedMs:    $elapsed,
                rowCount:     (int) ($envelope['meta']['row_count'] ?? 0),
                status:       MageAustralia_AiReports_Model_RunLog::STATUS_SUCCESS,
                errorMessage: null,
                emailSentTo:  $emailedTo,
            );

            Mage::log(
                sprintf(
                    'AiReports cron success report_id=%d elapsed_ms=%d row_count=%d emailed_to=%s',
                    $report->getId(),
                    $elapsed,
                    $envelope['meta']['row_count'] ?? 0,
                    $emailedTo ?? 'none',
                ),
                Mage::LOG_INFO,
                'aireports.log',
            );
        } catch (\Throwable $e) {
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            try {
                $report->setData('last_scheduled_at', Mage_Core_Model_Locale::nowUtc());
                $report->setData('last_scheduled_status', MageAustralia_AiReports_Model_RunLog::STATUS_ERROR);
                $report->setData('last_scheduled_error', substr($e->getMessage(), 0, 1000));
                $report->save();

                MageAustralia_AiReports_Model_RunLog::record(
                    reportId:     (int) $report->getId(),
                    triggeredBy:  MageAustralia_AiReports_Model_RunLog::TRIGGERED_BY_CRON,
                    startTime:    $start,
                    elapsedMs:    $elapsed,
                    rowCount:     0,
                    status:       MageAustralia_AiReports_Model_RunLog::STATUS_ERROR,
                    errorMessage: $e->getMessage(),
                    emailSentTo:  null,
                );
            } catch (\Throwable $inner) {
                Mage::log('AiReports cron: failed to persist error state: ' . $inner->getMessage(), Mage::LOG_ERROR, 'aireports.log');
            }

            Mage::log(
                'AiReports cron error report_id=' . $report->getId() . ': ' . $e->getMessage(),
                Mage::LOG_ERROR,
                'aireports.log',
            );
        }
    }

    /**
     * Send the report result by email if recipients are configured.
     * Returns a CSV string of the addresses emailed, or null if not sent.
     */
    private function maybeEmail(MageAustralia_AiReports_Model_Report $report, array $envelope): ?string
    {
        $recipientsCsv = (string) $report->getData('email_recipients');
        if ($recipientsCsv === '') {
            return null;
        }

        $addresses = $this->parseEmailList($recipientsCsv);
        if (empty($addresses)) {
            return null;
        }

        try {
            /** @var MageAustralia_AiReports_Helper_EmailRenderer $renderer */
            $renderer = Mage::helper('aireports/emailRenderer');
            $bodyHtml = $renderer->buildBodyHtml($envelope);

            $subjectPrefix = (string) $report->getData('email_subject_prefix');
            if ($subjectPrefix === '') {
                $subjectPrefix = (string) Mage::getStoreConfig('aireports/email/default_subject_prefix');
            }

            $fromName  = (string) Mage::getStoreConfig('aireports/email/from_name');
            $fromEmail = (string) Mage::getStoreConfig('aireports/email/from_email');
            if ($fromEmail === '') {
                $fromEmail = (string) Mage::getStoreConfig('trans_email/ident_general/email');
                $fromName  = $fromName !== '' ? $fromName : (string) Mage::getStoreConfig('trans_email/ident_general/name');
            }

            $detailUrl = Mage::helper('adminhtml')->getUrl('adminhtml/aireports/viewSaved', ['id' => $report->getId()]);

            $vars = [
                'subject_prefix' => $this->_formatSubjectPrefix($subjectPrefix),
                'report_title'   => (string) $report->getTitle(),
                'run_date'       => date('Y-m-d H:i') . ' UTC',
                'narrative'      => (string) ($envelope['narrative'] ?? ''),
                'body_html'      => $bodyHtml,
                'detail_url'     => $detailUrl,
            ];

            /** @var Mage_Core_Model_Email_Template $template */
            $template = Mage::getModel('core/email_template');
            $template->loadDefault('aireports_scheduled_run');

            $subject = $template->getProcessedTemplateSubject($vars);

            foreach ($addresses as $address) {
                $template->setSenderName($fromName);
                $template->setSenderEmail($fromEmail);
                $template->send($address, $address, $vars);
            }

            Mage::log(
                sprintf(
                    'AiReports cron email sent report_id=%d recipients=%s subject=%s',
                    $report->getId(),
                    implode(', ', $addresses),
                    $subject,
                ),
                Mage::LOG_INFO,
                'aireports.log',
            );

            return implode(', ', $addresses);
        } catch (\Throwable $e) {
            Mage::log(
                'AiReports cron email error report_id=' . $report->getId() . ': ' . $e->getMessage(),
                Mage::LOG_ERROR,
                'aireports.log',
            );
            return null;
        }
    }

    /**
     * Format the subject prefix. If the user already wrapped it in brackets ([Daily])
     * leave it alone; otherwise wrap. Always trail a space.
     */
    private function _formatSubjectPrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return '';
        }
        if (preg_match('/^\[.*\]$/', $prefix)) {
            return $prefix . ' ';
        }
        return '[' . $prefix . '] ';
    }

    /**
     * Parse a CSV email list, validate each address, return the valid ones.
     *
     * @return string[]
     */
    private function parseEmailList(string $csv): array
    {
        $valid = [];
        foreach (explode(',', $csv) as $raw) {
            $addr = trim($raw);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[] = $addr;
            }
        }
        return $valid;
    }
}
