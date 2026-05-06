<?php

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
            $helper->checkRateLimit();

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

            $rawJson = $this->_invokeWithRetry($question, $systemPrompt);
            $plan    = $this->_decodePlan($rawJson);

            Mage::log(
                'AiReports query_plan: q=' . substr($question, 0, 200) . ' plan=' . json_encode($plan, JSON_UNESCAPED_SLASHES),
                Mage::LOG_INFO,
                'aireports.log',
            );

            $validator = new MageAustralia_AiReports_Model_QueryPlanValidator($helper->getRegistry());
            $valid     = $validator->validate($plan, $stores);

            $executor = new MageAustralia_AiReports_Model_PrimitiveExecutor(
                $helper->getRegistry(),
                new MageAustralia_AiReports_Model_RenderEnvelopeBuilder(),
            );
            $envelope = $executor->run($valid['plan'], $valid['effectiveStoreIds'], $valid['scopeWarning']);

            $this->_jsonSuccess([
                'envelope'   => $envelope,
                'query_plan' => $valid['plan'],
                'render_hint' => $valid['plan']['render_hint'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Mage::log('AiReports generate error: ' . $e->getMessage(), Mage::LOG_ERROR);
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
            Mage::log('AiReports save error: ' . $e->getMessage(), Mage::LOG_ERROR);
            $this->_jsonError($e->getMessage());
        }
    }

    public function runSavedAction(): void
    {
        try {
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
            );
            $envelope = $executor->run($valid['plan'], $valid['effectiveStoreIds'], $valid['scopeWarning']);

            $report->setData('last_run_at', Varien_Date::now());
            $report->setData('last_run_elapsed_ms', $envelope['meta']['elapsed_ms']);
            $report->save();

            $this->_jsonSuccess(['envelope' => $envelope]);
        } catch (\Throwable $e) {
            Mage::log('AiReports runSaved error: ' . $e->getMessage(), Mage::LOG_ERROR);
            $this->_jsonError($e->getMessage());
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

    private function _invokeWithRetry(string $question, string $systemPrompt): string
    {
        /** @var Maho_Ai_Helper_Data $aiHelper */
        $aiHelper = Mage::helper('ai');
        try {
            return $aiHelper->invoke(
                userMessage: $question,
                systemPrompt: $systemPrompt,
                options: ['temperature' => 0.2],
                consumer: MageAustralia_AiReports_Helper_Data::CONSUMER,
            );
        } catch (\Throwable $first) {
            // Retry once with the error appended.
            return $aiHelper->invoke(
                userMessage: $question,
                systemPrompt: $systemPrompt . "\n\nYour previous response failed: " . $first->getMessage() . "\nTry again, JSON only.",
                options: ['temperature' => 0.2],
                consumer: MageAustralia_AiReports_Helper_Data::CONSUMER,
            );
        }
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
