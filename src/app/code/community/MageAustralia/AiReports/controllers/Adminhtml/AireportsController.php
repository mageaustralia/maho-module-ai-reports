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
            'save', 'rename', 'delete' => Mage::getSingleton('admin/session')->isAllowed('aireports/manage_saved'),
            default                    => Mage::getSingleton('admin/session')->isAllowed('aireports/run'),
        };
    }

    public function askAction(): void
    {
        $this->loadLayout();
        $this->_setActiveMenu('aireports');
        $this->_title($this->__('AI Reports'))->_title($this->__('Ask'));
        $this->renderLayout();
    }

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

            $stores  = $helper->getUserAccessibleStoreIds();
            $builder = new MageAustralia_AiReports_Model_PromptBuilder(
                $helper->getRegistry(),
                new \DateTimeImmutable('today'),
            );
            $systemPrompt = $builder->build($stores);

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
            Mage::log(
                sprintf(
                    'AiReports generate: user_id=%d elapsed_ms=%d row_count=%d q=%s plan=%s',
                    $userId,
                    $elapsedMs,
                    $envelope['meta']['row_count'] ?? 0,
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

    public function savedAction(): void
    {
        $this->loadLayout();
        $this->_setActiveMenu('aireports');
        $this->_title($this->__('AI Reports'))->_title($this->__('Saved Reports'));
        $this->renderLayout();
    }

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
        $this->_setActiveMenu('aireports/saved');
        $this->_title($this->__('AI Reports'))
             ->_title($this->__('Saved Reports'))
             ->_title($report->getTitle());
        $this->renderLayout();
    }

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

            $plan = $report->getQueryPlan();
            $validator = new MageAustralia_AiReports_Model_QueryPlanValidator($helper->getRegistry());
            $valid = $validator->validate($plan, $stores);

            $executor = new MageAustralia_AiReports_Model_PrimitiveExecutor(
                $helper->getRegistry(),
                new MageAustralia_AiReports_Model_RenderEnvelopeBuilder(),
                $helper,
            );
            $envelope = $executor->run($valid['plan'], $valid['effectiveStoreIds'], $valid['scopeWarning']);

            $elapsedMs = (int) ((microtime(true) - $tStart) * 1000);
            $report->setData('last_run_at', Mage_Core_Model_Locale::nowUtc());
            $report->setData('last_run_elapsed_ms', $envelope['meta']['elapsed_ms']);
            $report->save();

            $userId = (int) (Mage::getSingleton('admin/session')->getUser()?->getId() ?? 0);
            Mage::log(
                sprintf(
                    'AiReports runSaved: user_id=%d report_id=%d elapsed_ms=%d row_count=%d',
                    $userId,
                    $reportId,
                    $elapsedMs,
                    $envelope['meta']['row_count'] ?? 0,
                ),
                Mage::LOG_INFO,
                'aireports.log',
            );

            $this->_jsonSuccess(['envelope' => $envelope]);
        } catch (\Throwable $e) {
            Mage::log('AiReports runSaved error: ' . $e->getMessage(), Mage::LOG_ERROR, 'aireports.log');
            $this->_jsonError($e->getMessage());
        }
    }

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

    public function deleteAction(): void
    {
        try {
            Mage::getModel('aireports/report')->load((int) $this->getRequest()->getParam('id'))->delete();
            $this->_jsonSuccess([]);
        } catch (\Throwable $e) {
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

    /** @return array<string, mixed> */
    private function _decodePlan(string $raw): array
    {
        $stripped = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $decoded  = json_decode((string) $stripped, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Model returned non-JSON output.');
        }
        return $decoded;
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
