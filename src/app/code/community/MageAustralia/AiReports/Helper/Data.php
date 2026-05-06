<?php

declare(strict_types=1);

class MageAustralia_AiReports_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const CONSUMER = 'aireports';
    public const RATE_LIMIT_SECONDS = 5;

    private ?MageAustralia_AiReports_Model_PrimitiveRegistry $registry = null;

    public function getRegistry(): MageAustralia_AiReports_Model_PrimitiveRegistry
    {
        if ($this->registry === null) {
            $this->registry = new MageAustralia_AiReports_Model_PrimitiveRegistry();
            $this->registry->register(new MageAustralia_AiReports_Model_Primitive_TopN());
            $this->registry->register(new MageAustralia_AiReports_Model_Primitive_Growth());
            $this->registry->register(new MageAustralia_AiReports_Model_Primitive_TimeSeries());
            $this->registry->register(new MageAustralia_AiReports_Model_Primitive_Breakdown());
            $this->registry->register(new MageAustralia_AiReports_Model_Primitive_StockVsVelocity());
            $this->registry->register(new MageAustralia_AiReports_Model_Primitive_LowStock());
        }
        return $this->registry;
    }

    /** @return int[] */
    public function getUserAccessibleStoreIds(): array
    {
        $session = Mage::getSingleton('admin/session');
        $user    = $session->getUser();
        if (!$user) return [];
        $role    = $user->getRole();
        if (!$role) return [];

        // GWS_IS_ALL means all stores accessible.
        if ((int) $role->getGwsIsAll() === 1) {
            $allIds = [];
            foreach (Mage::app()->getStores(false) as $store) {
                $allIds[] = (int) $store->getId();
            }
            return $allIds;
        }

        // gws_stores is a CSV string of allowed store IDs.
        $csv = (string) $role->getGwsStores();
        if ($csv === '') return [];
        return array_map('intval', array_filter(explode(',', $csv), 'strlen'));
    }

    public function rateLimitKeyForCurrentUser(): string
    {
        $userId = (int) (Mage::getSingleton('admin/session')->getUser()?->getId() ?? 0);
        return 'aireports_last_invoke_' . $userId;
    }

    public function checkRateLimit(): void
    {
        $session = Mage::getSingleton('admin/session');
        $key     = $this->rateLimitKeyForCurrentUser();
        $last    = (int) $session->getData($key);
        $now     = time();
        if ($last && ($now - $last) < self::RATE_LIMIT_SECONDS) {
            Mage::throwException($this->__('Please wait a few seconds before submitting another report.'));
        }
        $session->setData($key, $now);
    }
}
