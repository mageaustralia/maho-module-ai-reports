<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

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
        if (!$user) {
            return [];
        }
        $role    = $user->getRole();
        if (!$role) {
            return [];
        }

        // GWS_IS_ALL means all stores accessible.
        if ((int) $role->getGwsIsAll() === 1) {
            $allIds = [];
            /** @phpstan-ignore-next-line method.notFound */
            foreach (Mage::app()->getStores(false) as $store) {
                $allIds[] = (int) $store->getId();
            }
            return $allIds;
        }

        // gws_stores is a CSV string of allowed store IDs.
        $csv = (string) $role->getGwsStores();
        if ($csv === '') {
            return [];
        }
        return array_map('intval', array_filter(explode(',', $csv), static fn(string $s): bool => $s !== ''));
    }

    public function rateLimitKeyForCurrentUser(): string
    {
        $userId = (int) (Mage::getSingleton('admin/session')->getUser()->getId() ?? 0);
        return 'aireports_last_invoke_' . $userId;
    }

    public function getLastInvokeTimestamp(): ?int
    {
        $key  = $this->rateLimitKeyForCurrentUser();
        $last = (int) Mage::getSingleton('admin/session')->getData($key);
        return $last > 0 ? $last : null;
    }

    public function recordInvoke(): void
    {
        $key = $this->rateLimitKeyForCurrentUser();
        Mage::getSingleton('admin/session')->setData($key, time());
    }

    public function canSeeCustomerPii(): bool
    {
        return (bool) Mage::getSingleton('admin/session')->isAllowed('customer/manage_customers');
    }

    public function getStoreTimezone(): string
    {
        /** @phpstan-ignore-next-line method.notFound */
        return (string) (Mage::app()->getStore()->getConfig('general/locale/timezone') ?: 'UTC');
    }

    public function newPeriodNormalizer(?\DateTimeImmutable $today = null): MageAustralia_AiReports_Model_PeriodNormalizer
    {
        $tz    = $this->getStoreTimezone();
        $today = $today ?? new \DateTimeImmutable('now', new \DateTimeZone($tz));
        return new MageAustralia_AiReports_Model_PeriodNormalizer($today, $tz);
    }

    /** @var array{id:int,code:string,backend_type:string,frontend_input:string}|false|null */
    private array|false|null $brandAttribute = null;

    /**
     * Resolve the product attribute that holds "brand" for the Sales-by-Brand
     * breakdown. Uses the admin setting if configured, otherwise auto-detects by
     * trying common codes and picking the first that exists AND is populated.
     *
     * @return array{id:int,code:string,backend_type:string,frontend_input:string}|null
     */
    public function getBrandAttribute(): ?array
    {
        if ($this->brandAttribute !== null) {
            return $this->brandAttribute ?: null;
        }

        $configured = trim((string) Mage::getStoreConfig('aireports/general/brand_attribute'));
        $candidates = $configured !== '' ? [$configured] : ['brand_id', 'brand', 'manufacturer'];

        foreach ($candidates as $code) {
            /** @var Mage_Eav_Model_Entity_Attribute_Abstract $attr */
            $attr = Mage::getSingleton('eav/config')->getAttribute('catalog_product', $code);
            if (!$attr || !$attr->getId()) {
                continue;
            }
            // For auto-detect, require the attribute to actually hold values so we
            // don't pick an empty placeholder (e.g. unused "manufacturer").
            if ($configured === '' && !$this->attributeHasValues($attr)) {
                continue;
            }
            $this->brandAttribute = [
                'id'             => (int) $attr->getId(),
                'code'           => (string) $attr->getAttributeCode(),
                'backend_type'   => (string) $attr->getBackendType(),
                'frontend_input' => (string) $attr->getFrontendInput(),
            ];
            return $this->brandAttribute;
        }

        $this->brandAttribute = false;
        return null;
    }

    private function attributeHasValues(Mage_Eav_Model_Entity_Attribute_Abstract $attr): bool
    {
        $resource = Mage::getSingleton('core/resource');
        $table = $resource->getTableName('catalog/product') . '_' . $attr->getBackendType();
        $conn = $resource->getConnection('core_read');
        if (!$conn->isTableExists($table)) {
            return false;
        }
        $select = $conn->select()
            ->from($table, [new Maho\Db\Expr('1')])
            ->where('attribute_id = ?', (int) $attr->getId())
            ->where('value IS NOT NULL')
            ->where('value <> ?', '')
            ->limit(1);
        return (bool) $conn->fetchOne($select);
    }
}
