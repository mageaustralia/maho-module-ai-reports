<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Adminhtml_AireportsController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed(): bool
    {
        return match ($this->getRequest()->getActionName()) {
            'save', 'rename', 'delete', 'schedule', 'pin', 'unpin' => Mage::getSingleton('admin/session')->isAllowed('aireports/manage_saved'),
            default                                                  => Mage::getSingleton('admin/session')->isAllowed('aireports/run'),
        };
    }

    #[\Maho\Config\Route('/admin/aireports/ask')]
    public function askAction(): void
    {
        $this->loadLayout();
        $this->_setActiveMenu('report/aireports');
        $this->_title($this->__('AI Reports'))->_title($this->__('Ask'));
        $this->renderLayout();
    }

    #[\Maho\Config\Route('/admin/aireports/generate')]
    public function generateAction(): void
    {
        try {
            /** @var MageAustralia_AiReports_Helper_Data $helper */
            $helper = Mage::helper('aireports');

            $last = $helper->getLastInvokeTimestamp();
            if ($last && (time() - $last) < MageAustralia_AiReports_Helper_Data::RATE_LIMIT_SECONDS) {
                $this->_jsonError('Please wait a few seconds before submitting another report.');
                return;
            }

            $question = (string) $this->getRequest()->getParam('q', '');
            if ($question === '') {
                $this->_jsonError('Please enter a question.');
                return;
            }

            $stores   = $helper->getUserAccessibleStoreIds();
            $storeId  = (int) Mage::app()->getStore()->getId();

            /** @var MageAustralia_AiReports_Helper_ProductResolver $resolver */
            $resolver = Mage::helper('aireports/productResolver');
            $resolved = $resolver->resolve($question, $storeId);

            $builder = new MageAustralia_AiReports_Model_PromptBuilder(
                $helper->getRegistry(),
                new \DateTimeImmutable('today'),
            );
            $systemPrompt = $builder->build($stores, $resolved);

            $tStart = microtime(true);
            $valid  = $this->_generatePlan($question, $systemPrompt, $stores);
            $elapsedMs = (int) ((microtime(true) - $tStart) * 1000);

            $executor = new MageAustralia_AiReports_Model_PrimitiveExecutor(
                $helper->getRegistry(),
                new MageAustralia_AiReports_Model_RenderEnvelopeBuilder(),
                $helper,
            );
            $envelope = $executor->run($valid['plan'], $valid['effectiveStoreIds'], $valid['scopeWarning']);

            $helper->recordInvoke();

            $userId = (int) (Mage::getSingleton('admin/session')->getUser()?->getId() ?? 0);
            $resolvedIds = array_map(fn ($p) => $p['id'] . ':' . $p['name'], $resolved);
            Mage::log(
                sprintf(
                    'AiReports generate: user_id=%d elapsed_ms=%d row_count=%d resolved_products=[%s] q=%s plan=%s',
                    $userId,
                    $elapsedMs,
                    $envelope['meta']['row_count'] ?? 0,
                    implode(', ', $resolvedIds),
                    substr($question, 0, 200),
                    json_encode($valid['plan'], JSON_UNESCAPED_SLASHES),
                ),
                Mage::LOG_INFO,
                'aireports.log',
            );

            $this->_jsonSuccess([
                'envelope'   => $envelope,
                'query_plan' => $valid['plan'],
                'render_hint' => $valid['plan']['render_hint'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Mage::log('AiReports generate error: ' . $e->getMessage(), Mage::LOG_ERROR, 'aireports.log');
            $this->_jsonError($e->getMessage());
        }
    }

    #[\Maho\Config\Route('/admin/aireports/saved')]
    public function savedAction(): void
    {
        $this->loadLayout();
        $this->_setActiveMenu('report/aireports');
        $this->_title($this->__('AI Reports'))->_title($this->__('Saved Reports'));
        $this->renderLayout();
    }

    #[\Maho\Config\Route('/admin/aireports/savedGrid')]
    public function savedGridAction(): void
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    #[\Maho\Config\Route('/admin/aireports/viewSaved')]
    public function viewSavedAction(): void
    {
        $reportId = (int) $this->getRequest()->getParam('id');
        /** @var MageAustralia_AiReports_Model_Report $report */
        $report = Mage::getModel('aireports/report')->load($reportId);
        if (!$report->getId()) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Saved report not found.'));
            $this->_redirect('*/*/saved');
            return;
        }
        Mage::register('aireports_current_report', $report);

        $this->loadLayout();
        $this->_setActiveMenu('report/aireports/saved');
        $this->_title($this->__('AI Reports'))
             ->_title($this->__('Saved Reports'))
             ->_title($report->getTitle());
        $this->renderLayout();
    }

    #[\Maho\Config\Route('/admin/aireports/save')]
    public function saveAction(): void
    {
        try {
            $title    = (string) $this->getRequest()->getParam('title', '');
            $planJson = (string) $this->getRequest()->getParam('query_plan_json', '');
            $hintJson = (string) $this->getRequest()->getParam('render_hint_json', '{}');

            if ($title === '' || $planJson === '') {
                $this->_jsonError('Title and query plan required.');
                return;
            }

            /** @var MageAustralia_AiReports_Model_Report $report */
            $report = Mage::getModel('aireports/report');
            $report->setTitle($title);
            $report->setData('query_plan_json', $planJson);
            $report->setData('render_hint_json', $hintJson);
            $report->setData('created_by_user_id', Mage::getSingleton('admin/session')->getUser()->getId());
            $report->save();

            $this->_jsonSuccess(['report_id' => (int) $report->getId()]);
        } catch (\Throwable $e) {
            Mage::log('AiReports save error: ' . $e->getMessage(), Mage::LOG_ERROR, 'aireports.log');
            $this->_jsonError($e->getMessage());
        }
    }

    #[\Maho\Config\Route('/admin/aireports/runSaved')]
    public function runSavedAction(): void
    {
        try {
            $tStart   = microtime(true);
            $reportId = (int) $this->getRequest()->getParam('id');
            /** @var MageAustralia_AiReports_Model_Report $report */
            $report = Mage::getModel('aireports/report')->load($reportId);
            if (!$report->getId()) {
                $this->_jsonError('Report not found.');
                return;
            }

            /** @var MageAustralia_AiReports_Helper_Data $helper */
            $helper = Mage::helper('aireports');
            $stores = $helper->getUserAccessibleStoreIds();

            $periodFrom = $this->getRequest()->getParam('period_from') ?: null;
            $periodTo   = $this->getRequest()->getParam('period_to') ?: null;

            $plan = $report->getQueryPlan();
            $plan = MageAustralia_AiReports_Model_PeriodOverrider::applyOverride($plan, $periodFrom, $periodTo);
            $overrideActive = $periodFrom !== null && $periodTo !== null
                && MageAustralia_AiReports_Model_PeriodOverrider::isValidIsoDate($periodFrom)
                && MageAustralia_AiReports_Model_PeriodOverrider::isValidIsoDate($periodTo);

            $validator = new MageAustralia_AiReports_Model_QueryPlanValidator($helper->getRegistry());
            $valid = $validator->validate($plan, $stores);

            $executor = new MageAustralia_AiReports_Model_PrimitiveExecutor(
                $helper->getRegistry(),
                new MageAustralia_AiReports_Model_RenderEnvelopeBuilder(),
                $helper,
            );
            $envelope = $executor->run($valid['plan'], $valid['effectiveStoreIds'], $valid['scopeWarning']);

            $elapsedMs = (int) ((microtime(true) - $tStart) * 1000);

            // Do not write last_run_at when a period override is active - the saved plan
            // was not used as-is, so updating the metadata would be misleading.
            if (!$overrideActive) {
                $report->setData('last_run_at', Mage_Core_Model_Locale::nowUtc());
                $report->setData('last_run_elapsed_ms', $envelope['meta']['elapsed_ms']);
                $report->save();
            }

            $userId = (int) (Mage::getSingleton('admin/session')->getUser()?->getId() ?? 0);
            $overrideTag = $overrideActive ? ' period_override=' . $periodFrom . '..' . $periodTo : '';
            Mage::log(
                sprintf(
                    'AiReports runSaved: user_id=%d report_id=%d elapsed_ms=%d row_count=%d%s',
                    $userId,
                    $reportId,
                    $elapsedMs,
                    $envelope['meta']['row_count'] ?? 0,
                    $overrideTag,
                ),
                Mage::LOG_INFO,
                'aireports.log',
            );

            $this->_jsonSuccess(['envelope' => $envelope, 'query_plan' => $valid['plan']]);
        } catch (\Throwable $e) {
            Mage::log('AiReports runSaved error: ' . $e->getMessage(), Mage::LOG_ERROR, 'aireports.log');
            $this->_jsonError($e->getMessage());
        }
    }

    #[\Maho\Config\Route('/admin/aireports/exportCsv')]
    public function exportCsvAction(): void
    {
        try {
            $planJson = (string) $this->getRequest()->getParam('query_plan_json', '');
            if ($planJson === '') {
                $this->getResponse()->setHttpResponseCode(400)->setBody('query_plan_json is required.');
                return;
            }

            $plan = json_decode($planJson, true);
            if (!is_array($plan)) {
                $this->getResponse()->setHttpResponseCode(400)->setBody('Invalid query_plan_json.');
                return;
            }

            /** @var MageAustralia_AiReports_Helper_Data $helper */
            $helper = Mage::helper('aireports');
            $stores = $helper->getUserAccessibleStoreIds();

            $validator = new MageAustralia_AiReports_Model_QueryPlanValidator($helper->getRegistry());
            $valid     = $validator->validate($plan, $stores);

            $executor = new MageAustralia_AiReports_Model_PrimitiveExecutor(
                $helper->getRegistry(),
                new MageAustralia_AiReports_Model_RenderEnvelopeBuilder(),
                $helper,
            );
            $envelope = $executor->run($valid['plan'], $valid['effectiveStoreIds'], $valid['scopeWarning']);

            $tableBlock = null;
            foreach ($envelope['blocks'] as $block) {
                if ($block['type'] === 'table') {
                    $tableBlock = $block;
                    break;
                }
            }

            if ($tableBlock === null) {
                $this->getResponse()->setHttpResponseCode(422)->setBody('No table block in result.');
                return;
            }

            $now      = Mage_Core_Model_Locale::nowUtc();
            $filename = 'report-' . str_replace([' ', ':'], ['-', ''], substr($now, 0, 19)) . '.csv';

            $userId = (int) (Mage::getSingleton('admin/session')->getUser()?->getId() ?? 0);
            Mage::log(
                sprintf(
                    'AiReports exportCsv: user_id=%d row_count=%d file=%s',
                    $userId,
                    count($tableBlock['rows']),
                    $filename,
                ),
                Mage::LOG_INFO,
                'aireports.log',
            );

            $this->getResponse()
                ->setHeader('Content-Type', 'text/csv; charset=utf-8', true)
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"', true)
                ->setHeader('Cache-Control', 'no-store', true)
                ->setHeader('Pragma', 'no-cache', true);

            $out = fopen('php://output', 'w');
            // BOM for Excel UTF-8 compatibility.
            fwrite($out, "\xEF\xBB\xBF");
            // Header row: column labels.
            fputcsv($out, array_map(fn ($c) => $c['label'], $tableBlock['columns']));
            // Data rows.
            foreach ($tableBlock['rows'] as $row) {
                $cells = [];
                foreach ($tableBlock['columns'] as $col) {
                    $cells[] = $row['cells'][$col['key']] ?? '';
                }
                fputcsv($out, $cells);
            }
            fclose($out);
        } catch (\Throwable $e) {
            Mage::log('AiReports exportCsv error: ' . $e->getMessage(), Mage::LOG_ERROR, 'aireports.log');
            $this->getResponse()->setHttpResponseCode(500)->setBody('Export failed: ' . $e->getMessage());
        }
    }

    #[\Maho\Config\Route('/admin/aireports/exportSavedCsv')]
    public function exportSavedCsvAction(): void
    {
        try {
            $reportId = (int) $this->getRequest()->getParam('id');
            /** @var MageAustralia_AiReports_Model_Report $report */
            $report = Mage::getModel('aireports/report')->load($reportId);
            if (!$report->getId()) {
                $this->getResponse()->setHttpResponseCode(404)->setBody('Report not found.');
                return;
            }

            /** @var MageAustralia_AiReports_Helper_Data $helper */
            $helper = Mage::helper('aireports');
            $stores = $helper->getUserAccessibleStoreIds();

            $plan = $report->getQueryPlan();
            $validator = new MageAustralia_AiReports_Model_QueryPlanValidator($helper->getRegistry());
            $valid     = $validator->validate($plan, $stores);

            $executor = new MageAustralia_AiReports_Model_PrimitiveExecutor(
                $helper->getRegistry(),
                new MageAustralia_AiReports_Model_RenderEnvelopeBuilder(),
                $helper,
            );
            $envelope = $executor->run($valid['plan'], $valid['effectiveStoreIds'], $valid['scopeWarning']);

            $tableBlock = null;
            foreach ($envelope['blocks'] as $block) {
                if ($block['type'] === 'table') {
                    $tableBlock = $block;
                    break;
                }
            }

            if ($tableBlock === null) {
                $this->getResponse()->setHttpResponseCode(422)->setBody('No table block in result.');
                return;
            }

            $now      = Mage_Core_Model_Locale::nowUtc();
            $filename = 'report-' . str_replace([' ', ':'], ['-', ''], substr($now, 0, 19)) . '.csv';

            $userId = (int) (Mage::getSingleton('admin/session')->getUser()?->getId() ?? 0);
            Mage::log(
                sprintf(
                    'AiReports exportSavedCsv: user_id=%d report_id=%d row_count=%d file=%s',
                    $userId,
                    $reportId,
                    count($tableBlock['rows']),
                    $filename,
                ),
                Mage::LOG_INFO,
                'aireports.log',
            );

            $this->getResponse()
                ->setHeader('Content-Type', 'text/csv; charset=utf-8', true)
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"', true)
                ->setHeader('Cache-Control', 'no-store', true)
                ->setHeader('Pragma', 'no-cache', true);

            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($c) => $c['label'], $tableBlock['columns']));
            foreach ($tableBlock['rows'] as $row) {
                $cells = [];
                foreach ($tableBlock['columns'] as $col) {
                    $cells[] = $row['cells'][$col['key']] ?? '';
                }
                fputcsv($out, $cells);
            }
            fclose($out);
        } catch (\Throwable $e) {
            Mage::log('AiReports exportSavedCsv error: ' . $e->getMessage(), Mage::LOG_ERROR, 'aireports.log');
            $this->getResponse()->setHttpResponseCode(500)->setBody('Export failed: ' . $e->getMessage());
        }
    }

    /**
     * POST - update schedule fields on a saved report.
     * Validates cron expression and email list before saving.
     * ACL: aireports/manage_saved
     */
    #[\Maho\Config\Route('/admin/aireports/schedule')]
    public function scheduleAction(): void
    {
        try {
            $reportId = (int) $this->getRequest()->getParam('id');
            /** @var MageAustralia_AiReports_Model_Report $report */
            $report = Mage::getModel('aireports/report')->load($reportId);
            if (!$report->getId()) {
                $this->_jsonError('Report not found.');
                return;
            }

            $enabled    = (int) (bool) $this->getRequest()->getParam('schedule_enabled', 0);
            $cronExpr   = trim((string) $this->getRequest()->getParam('schedule_cron_expr', ''));
            $recipients = trim((string) $this->getRequest()->getParam('email_recipients', ''));
            $prefix     = trim((string) $this->getRequest()->getParam('email_subject_prefix', ''));

            // Validate cron expression when provided.
            if ($cronExpr !== '' && !MageAustralia_AiReports_Model_CronExpressionMatcher::isValid($cronExpr)) {
                $this->_jsonError('Invalid cron expression. Use 5 space-separated fields, e.g. "0 9 * * 1".');
                return;
            }

            // Validate each email address.
            if ($recipients !== '') {
                foreach (explode(',', $recipients) as $addr) {
                    $addr = trim($addr);
                    if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
                        $this->_jsonError('Invalid email address: ' . $addr);
                        return;
                    }
                }
            }

            $report->setData('schedule_enabled',     $enabled)
                   ->setData('schedule_cron_expr',   $cronExpr !== '' ? $cronExpr : null)
                   ->setData('email_recipients',     $recipients !== '' ? $recipients : null)
                   ->setData('email_subject_prefix', $prefix !== '' ? $prefix : null)
                   ->save();

            $this->_jsonSuccess([
                'schedule_enabled'    => $enabled,
                'schedule_cron_expr'  => $cronExpr,
                'email_recipients'    => $recipients,
                'email_subject_prefix' => $prefix,
            ]);
        } catch (\Throwable $e) {
            Mage::log('AiReports schedule error: ' . $e->getMessage(), Mage::LOG_ERROR, 'aireports.log');
            $this->_jsonError($e->getMessage());
        }
    }

    /**
     * GET/AJAX - render the run-log grid (sort, filter, pagination).
     * Used by the Run History tab grid widget.
     * ACL: aireports/run
     */
    #[\Maho\Config\Route('/admin/aireports/runlog')]
    public function runlogAction(): void
    {
        $reportId = (int) $this->getRequest()->getParam('id');
        $report = Mage::getModel('aireports/report')->load($reportId);
        if (!$report->getId()) {
            $this->_jsonError('Report not found.');
            return;
        }
        Mage::register('aireports_current_report', $report);
        $this->loadLayout();
        $this->renderLayout();
    }

    /**
     * POST - return contributing records for a specific result row (inline drilldown).
     * ACL: aireports/run (default branch in _isAllowed()).
     */
    #[\Maho\Config\Route('/admin/aireports/drill')]
    public function drillAction(): void
    {
        try {
            $planJson = (string) $this->getRequest()->getParam('query_plan_json', '');
            $rowKeyJson = (string) $this->getRequest()->getParam('row_key_json', '');
            if ($planJson === '' || $rowKeyJson === '') {
                $this->_jsonError('Missing query_plan_json or row_key_json.');
                return;
            }
            $plan   = json_decode($planJson, true);
            $rowKey = json_decode($rowKeyJson, true);
            if (!is_array($plan) || !is_array($rowKey)) {
                $this->_jsonError('Invalid JSON.');
                return;
            }
            $helper = Mage::helper('aireports');
            $stores = $helper->getUserAccessibleStoreIds();
            $validator = new MageAustralia_AiReports_Model_QueryPlanValidator($helper->getRegistry());
            $valid = $validator->validate($plan, $stores);
            $primitive = $helper->getRegistry()->get($valid['plan']['primitive']);
            $rows = $primitive->drill($valid['plan']['args'] ?? [], $valid['effectiveStoreIds'], $rowKey);
            if ($rows === null) {
                $this->_jsonError('Drilldown not supported for this primitive.');
                return;
            }
            $this->_jsonSuccess(['rows' => $rows]);
        } catch (\Throwable $e) {
            Mage::log('AiReports drill error: ' . $e->getMessage(), Mage::LOG_ERROR, 'aireports.log');
            $this->_jsonError($e->getMessage());
        }
    }

    #[\Maho\Config\Route('/admin/aireports/rename')]
    public function renameAction(): void
    {
        try {
            $report = Mage::getModel('aireports/report')->load((int) $this->getRequest()->getParam('id'));
            if (!$report->getId()) { $this->_jsonError('Not found.'); return; }
            $report->setTitle((string) $this->getRequest()->getParam('title', $report->getTitle()))->save();
            $this->_jsonSuccess([]);
        } catch (\Throwable $e) {
            $this->_jsonError($e->getMessage());
        }
    }

    #[\Maho\Config\Route('/admin/aireports/delete')]
    public function deleteAction(): void
    {
        try {
            Mage::getModel('aireports/report')->load((int) $this->getRequest()->getParam('id'))->delete();
            $this->_jsonSuccess([]);
        } catch (\Throwable $e) {
            $this->_jsonError($e->getMessage());
        }
    }

    #[\Maho\Config\Route('/admin/aireports/pin')]
    public function pinAction(): void
    {
        try {
            $reportId = (int) $this->getRequest()->getParam('id');
            /** @var MageAustralia_AiReports_Model_Report $report */
            $report = Mage::getModel('aireports/report')->load($reportId);
            if (!$report->getId()) {
                $this->_jsonError('Report not found.');
                return;
            }
            $report->setData('is_pinned_to_dashboard', 1);
            $resource = Mage::getSingleton('core/resource');
            $conn     = $resource->getConnection('core_write');
            $maxSort  = (int) $conn->fetchOne(
                'SELECT MAX(pinned_sort_order) FROM '
                . $resource->getTableName('aireports/report')
                . ' WHERE is_pinned_to_dashboard = 1'
            );
            $report->setData('pinned_sort_order', $maxSort + 1);
            $report->save();
            $this->_jsonSuccess(['is_pinned' => true]);
        } catch (\Throwable $e) {
            Mage::log('AiReports pin error: ' . $e->getMessage(), Mage::LOG_ERROR, 'aireports.log');
            $this->_jsonError($e->getMessage());
        }
    }

    #[\Maho\Config\Route('/admin/aireports/unpin')]
    public function unpinAction(): void
    {
        try {
            $reportId = (int) $this->getRequest()->getParam('id');
            /** @var MageAustralia_AiReports_Model_Report $report */
            $report = Mage::getModel('aireports/report')->load($reportId);
            if (!$report->getId()) {
                $this->_jsonError('Report not found.');
                return;
            }
            $report->setData('is_pinned_to_dashboard', 0);
            $report->setData('pinned_sort_order', 0);
            $report->save();
            $this->_jsonSuccess(['is_pinned' => false]);
        } catch (\Throwable $e) {
            Mage::log('AiReports unpin error: ' . $e->getMessage(), Mage::LOG_ERROR, 'aireports.log');
            $this->_jsonError($e->getMessage());
        }
    }

    /**
     * Runs the full pipeline: LLM call -> decode -> schema validation.
     * Retries once (max 2 LLM calls) appending the error to the system prompt.
     *
     * @param int[] $userAccessibleStoreIds
     * @return array{effectiveStoreIds: int[], scopeWarning: bool, plan: array<string, mixed>}
     */
    private function _generatePlan(string $question, string $systemPrompt, array $userAccessibleStoreIds): array
    {
        /** @var Maho_Ai_Helper_Data $aiHelper */
        $aiHelper  = Mage::helper('ai');
        $helper    = Mage::helper('aireports');
        $validator = new MageAustralia_AiReports_Model_QueryPlanValidator($helper->getRegistry());

        $lastError = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $prompt = $systemPrompt;
            if ($lastError !== null) {
                $prompt .= "\n\nYour previous response failed: " . $lastError . "\nTry again, JSON only.";
            }
            try {
                $rawJson = $aiHelper->invoke(
                    userMessage: $question,
                    systemPrompt: $prompt,
                    options: ['temperature' => 0.2],
                    consumer: MageAustralia_AiReports_Helper_Data::CONSUMER,
                );
                $plan  = $this->_decodePlan($rawJson);
                return $validator->validate($plan, $userAccessibleStoreIds);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new \RuntimeException('Could not generate a valid report plan. ' . $lastError);
    }

    /**
     * Decode the LLM's JSON plan from a raw response. Handles three shapes:
     *   1. Pure JSON.
     *   2. JSON wrapped in ```json fences (any position).
     *   3. JSON embedded in prose - extract the first balanced top-level object.
     * @return array<string, mixed>
     */
    private function _decodePlan(string $raw): array
    {
        $stripped = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $decoded  = json_decode((string) $stripped, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $extracted = $this->_extractFirstJsonObject((string) $stripped);
        if ($extracted !== null) {
            $decoded = json_decode($extracted, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \InvalidArgumentException('Model returned non-JSON output.');
    }

    /**
     * Walk the string char-by-char tracking brace depth to extract the first
     * top-level {...} block. Respects string literals so braces inside JSON
     * strings do not count toward depth.
     */
    private function _extractFirstJsonObject(string $s): ?string
    {
        $start = strpos($s, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $inStr = false;
        $esc   = false;
        $len   = strlen($s);
        for ($i = $start; $i < $len; $i++) {
            $c = $s[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($c === '\\') {
                    $esc = true;
                } elseif ($c === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($c === '"') {
                $inStr = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }

    private function _jsonSuccess(array $data): void
    {
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(json_encode(['success' => true] + $data));
    }

    private function _jsonError(string $message): void
    {
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(json_encode(['success' => false, 'message' => $message]));
    }
}
