<?php

declare(strict_types=1);

class MageAustralia_AiReports_Model_Report extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('aireports/report');
    }

    protected function _beforeSave()
    {
        $now = Varien_Date::now();
        if (!$this->getId()) {
            $this->setCreatedAt($now);
        }
        $this->setUpdatedAt($now);
        return parent::_beforeSave();
    }

    /** @return array<string, mixed> */
    public function getQueryPlan(): array
    {
        return json_decode((string) $this->getData('query_plan_json'), true) ?? [];
    }

    public function setQueryPlan(array $plan): self
    {
        $this->setData('query_plan_json', json_encode($plan, JSON_UNESCAPED_SLASHES));
        return $this;
    }

    /** @return array<string, mixed> */
    public function getRenderHint(): array
    {
        return json_decode((string) $this->getData('render_hint_json'), true) ?? [];
    }

    public function setRenderHint(array $hint): self
    {
        $this->setData('render_hint_json', json_encode($hint, JSON_UNESCAPED_SLASHES));
        return $this;
    }
}
