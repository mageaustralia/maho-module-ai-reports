<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_Primitive_StockVsVelocity
    implements MageAustralia_AiReports_Model_PrimitiveInterface
{
    use MageAustralia_AiReports_Model_Primitive_UrlBuilderTrait;
    public function getName(): string { return 'stock_vs_velocity'; }

    public function getDescription(): string
    {
        return 'For a set of products, returns current stock-on-hand alongside sales velocity over the ' .
               'lookback window and computed days-of-cover. product_filter is one of: ' .
               '{type:"top_n_sellers", n, period}, {type:"skus", values}, {type:"category_id", value}.';
    }

    public function getArgsSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['product_filter', 'lookback_days'],
            'additionalProperties' => false,
            'properties' => [
                'product_filter' => [
                    'type'  => 'object',
                    'oneOf' => [
                        ['required' => ['type', 'n', 'period'], 'properties' => [
                            'type' => ['const' => 'top_n_sellers'],
                            'n'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                            'period' => MageAustralia_AiReports_Model_PeriodNormalizer::schema(),
                        ]],
                        ['required' => ['type', 'values'], 'properties' => [
                            'type'   => ['const' => 'skus'],
                            'values' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 200],
                        ]],
                        ['required' => ['type', 'value'], 'properties' => [
                            'type'  => ['const' => 'category_id'],
                            'value' => ['type' => 'integer', 'minimum' => 1],
                        ]],
                    ],
                ],
                'lookback_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 730],
                'store_ids'     => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
            ],
        ];
    }

    public function getDefaultRender(): array
    {
        return ['primary' => 'table'];
    }

    /**
     * Drilldown is not applicable to stock_vs_velocity (single-value series, not record aggregations).
     */
    public function drill(array $args, array $scopeStoreIds, array $rowKey): ?array
    {
        return null;
    }

    public function execute(array $args, array $scopeStoreIds): array
    {
        return $this->shapeRows($this->fetchRawRows($args, $scopeStoreIds));
    }

    /**
     * Returns raw DB rows with sku, label, product_id, qty_on_hand, qty_sold, lookback_days.
     * Separated from execute() so that LowStock can consume raw rows without a lossy round-trip.
     *
     * @param array<string, mixed> $args
     * @param int[] $scopeStoreIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchRawRows(array $args, array $scopeStoreIds): array
    {
        $conn = Mage::getSingleton('core/resource')->getConnection('core_read');
        $r    = Mage::getSingleton('core/resource');

        $productIds = $this->resolveProductFilter($conn, $r, $args['product_filter'], $scopeStoreIds);
        if (empty($productIds)) {
            return [];
        }

        $lookbackDays = (int) $args['lookback_days'];
        $from = (new \DateTimeImmutable("-$lookbackDays days"))->format('Y-m-d 00:00:00');
        $to   = (new \DateTimeImmutable('today'))->format('Y-m-d 23:59:59');

        $salesSubSelect = $conn->select()
            ->from(['oi2' => $r->getTableName('sales/order_item')], [
                'product_id' => 'oi2.product_id',
                'qty_sold'   => new Maho\Db\Expr('SUM(oi2.qty_ordered)'),
                'name'       => new Maho\Db\Expr('MAX(oi2.name)'),
            ])
            ->joinInner(['o2' => $r->getTableName('sales/order')], 'o2.entity_id = oi2.order_id', [])
            ->where('o2.created_at >= ?', $from)
            ->where('o2.created_at <= ?', $to)
            ->where('o2.state NOT IN (?)', ['canceled', 'closed'])
            ->group('oi2.product_id');

        if (!empty($scopeStoreIds)) {
            $salesSubSelect->where('o2.store_id IN (?)', $scopeStoreIds);
        }

        $select = $conn->select()
            ->from(['p' => $r->getTableName('catalog/product')], ['product_id' => 'entity_id', 'sku'])
            ->joinLeft(['stock' => $r->getTableName('cataloginventory/stock_item')],
                'stock.product_id = p.entity_id', ['qty_on_hand' => 'stock.qty'])
            ->joinLeft(['sales' => new Maho\Db\Expr('(' . $salesSubSelect . ')')],
                'sales.product_id = p.entity_id', [
                    'qty_sold' => new Maho\Db\Expr('COALESCE(sales.qty_sold, 0)'),
                    'name'     => 'sales.name',
                ])
            ->where('p.entity_id IN (?)', $productIds);

        $rows = $conn->fetchAll($select);
        foreach ($rows as &$row) {
            $row['lookback_days'] = $lookbackDays;
            $row['label']         = $row['name'] ?: $row['sku'];
        }
        unset($row);

        return $rows;
    }

    /** @return int[] */
    private function resolveProductFilter(
        Maho\Db\Adapter\AdapterInterface $conn,
        Mage_Core_Model_Resource $r,
        array $filter,
        array $scopeStoreIds,
    ): array {
        switch ($filter['type']) {
            case 'top_n_sellers':
                $period = (new MageAustralia_AiReports_Model_PeriodNormalizer())->resolve($filter['period']);
                $sel = $conn->select()
                    ->from(['oi' => $r->getTableName('sales/order_item')], ['product_id'])
                    ->joinInner(['o' => $r->getTableName('sales/order')], 'o.entity_id = oi.order_id', [])
                    ->where('o.created_at >= ?', $period['from'])
                    ->where('o.created_at <= ?', $period['to'])
                    ->where('o.state NOT IN (?)', ['canceled', 'closed'])
                    ->group('oi.product_id')
                    ->order(new Maho\Db\Expr('SUM(oi.qty_ordered) DESC'))
                    ->limit((int) $filter['n']);
                if (!empty($scopeStoreIds)) {
                    $sel->where('o.store_id IN (?)', $scopeStoreIds);
                }
                return array_map('intval', $conn->fetchCol($sel));

            case 'skus':
                $sel = $conn->select()
                    ->from(['p' => $r->getTableName('catalog/product')], ['entity_id'])
                    ->where('p.sku IN (?)', $filter['values']);
                return array_map('intval', $conn->fetchCol($sel));

            case 'category_id':
                $sel = $conn->select()
                    ->from(['ccp' => $r->getTableName('catalog/category_product')], ['product_id'])
                    ->where('ccp.category_id = ?', (int) $filter['value']);
                return array_map('intval', $conn->fetchCol($sel));
        }
        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows  rows with sku, label, product_id, qty_on_hand, qty_sold, lookback_days
     * @return array<int, array<string, mixed>>
     */
    public function shapeRows(array $rawRows): array
    {
        $shaped = [];
        foreach ($rawRows as $row) {
            $qtySold      = (float) ($row['qty_sold'] ?? 0);
            $lookbackDays = (int) ($row['lookback_days'] ?? 30);
            $velocity     = $lookbackDays > 0 ? round($qtySold / $lookbackDays, 2) : 0.0;
            $qtyOnHand    = (float) ($row['qty_on_hand'] ?? 0);
            $daysOfCover  = $velocity > 0 ? round($qtyOnHand / $velocity, 1) : null;

            $entry = [
                'sku'            => (string) $row['sku'],
                'label'          => (string) ($row['label'] ?? $row['sku']),
                'qty_on_hand'    => $qtyOnHand,
                'daily_velocity' => $velocity,
                'days_of_cover'  => $daysOfCover,
                'link_id'        => isset($row['product_id']) ? (int) $row['product_id'] : null,
            ];
            if ($entry['link_id']) {
                $entry['link_url'] = $this->buildAdminUrl('adminhtml/catalog_product/edit', ['id' => $entry['link_id']]);
            }
            $shaped[] = $entry;
        }
        return $shaped;
    }
}
