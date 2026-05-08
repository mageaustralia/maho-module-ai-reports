<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_Primitive_TopN
    implements MageAustralia_AiReports_Model_PrimitiveInterface
{
    use MageAustralia_AiReports_Model_Primitive_UrlBuilderTrait;
    public function getName(): string { return 'top_n'; }

    public function getDescription(): string
    {
        return 'Returns the top N records ranked by a metric over a period, optionally filtered by store. ' .
               'Use when the user asks "top sellers", "best customers", "highest-revenue categories", etc.';
    }

    public function getArgsSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['metric', 'dimension', 'period', 'limit'],
            'additionalProperties' => false,
            'properties'           => [
                'metric'    => ['type' => 'string', 'enum' => ['qty_sold', 'revenue', 'net_revenue', 'order_count', 'aov', 'margin']],
                'dimension' => ['type' => 'string', 'enum' => ['product', 'sku', 'customer', 'store', 'order_status']],
                'period'    => MageAustralia_AiReports_Model_PeriodNormalizer::schema(),
                'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                'store_ids'   => [
                    'type'  => ['array', 'null'],
                    'items' => ['type' => 'integer'],
                ],
                'product_ids' => [
                    'type'        => ['array', 'null'],
                    'items'       => ['type' => 'integer'],
                    'description' => 'Optional list of product IDs to filter results to (for queries about specific products).',
                ],
                'display_metrics' => [
                    'type'        => ['array', 'null'],
                    'items'       => ['type' => 'string', 'enum' => ['qty_sold', 'revenue', 'net_revenue', 'order_count', 'aov', 'margin']],
                    'description' => 'Additional metric columns to show alongside the sort metric. The `metric` arg still determines the sort order; these are display-only.',
                    'maxItems'    => 4,
                ],
            ],
        ];
    }

    public function getDefaultRender(): array
    {
        return ['primary' => 'bar_chart', 'secondary' => 'table'];
    }

    public function execute(array $args, array $scopeStoreIds): array
    {
        $conn   = Mage::getSingleton('core/resource')->getConnection('core_read');
        $r      = Mage::getSingleton('core/resource');
        $period = Mage::helper('aireports')->newPeriodNormalizer()->resolve($args['period']);
        $select = $this->buildSelect($conn, $r, $args, $scopeStoreIds, $period);
        return $this->shapeRows($conn->fetchAll($select), $args['dimension']);
    }

    /** @internal made public for buildable testing only */
    public function buildSelect(
        Maho\Db\Adapter\AdapterInterface $conn,
        Mage_Core_Model_Resource $r,
        array $args,
        array $scopeStoreIds,
        array $period,
    ): Maho\Db\Select {
        $orderItem = $r->getTableName('sales/order_item');
        $order     = $r->getTableName('sales/order');

        $valueExprs = [
            'qty_sold'    => 'SUM(oi.qty_ordered)',
            'revenue'     => 'SUM(oi.row_total - oi.discount_amount)',
            'net_revenue' => 'SUM(o.grand_total)',
            'order_count' => 'COUNT(DISTINCT o.entity_id)',
            'aov'         => 'SUM(o.grand_total) / NULLIF(COUNT(DISTINCT o.entity_id), 0)',
            'margin'      => 'SUM(oi.row_total - oi.discount_amount - (oi.qty_ordered * oi.base_cost))',
        ];

        $dimensionExprs = $this->dimensionExprs($r, $args['dimension']);

        $select = $conn->select()
            ->from(['oi' => $orderItem], [])
            ->joinInner(['o' => $order], 'o.entity_id = oi.order_id', []);

        if ($args['dimension'] === 'store') {
            $select->joinLeft(['cs' => $r->getTableName('core/store')], 'cs.store_id = o.store_id', []);
        }

        $columns = [
            'label'   => $dimensionExprs['label'],
            'link_id' => $dimensionExprs['link_id'],
            'value'   => new Maho\Db\Expr($valueExprs[$args['metric']]),
        ];

        $extras = $args['display_metrics'] ?? [];
        foreach ($extras as $extra) {
            if ($extra === $args['metric']) continue;
            if (!isset($valueExprs[$extra])) continue;
            $columns[$extra] = new Maho\Db\Expr($valueExprs[$extra]);
        }

        $select
            ->columns($columns)
            ->where('o.created_at >= ?', $period['from'])
            ->where('o.created_at <= ?', $period['to'])
            ->where('o.state NOT IN (?)', ['canceled', 'closed'])
            ->group($dimensionExprs['group_by'])
            ->order(new Maho\Db\Expr('value DESC'))
            ->limit((int) $args['limit']);

        if (!empty($scopeStoreIds)) {
            $select->where('o.store_id IN (?)', $scopeStoreIds);
        }

        if (!empty($args['product_ids'])) {
            $select->where('oi.product_id IN (?)', $args['product_ids']);
        }

        return $select;
    }

    /** @return array{label: string|Maho\Db\Expr, link_id: string|Maho\Db\Expr, group_by: string|array<int,string>} */
    private function dimensionExprs(Mage_Core_Model_Resource $r, string $dimension): array
    {
        return match ($dimension) {
            'product', 'sku' => [
                'label'    => 'oi.name',
                'link_id'  => 'oi.product_id',
                'group_by' => ['oi.product_id', 'oi.name'],
            ],
            'category' => $this->categoryExprs($r),
            'brand'    => $this->brandExprs($r),
            'customer' => [
                'label'    => new Maho\Db\Expr("COALESCE(o.customer_email, 'Guest')"),
                'link_id'  => 'o.customer_id',
                'group_by' => ['o.customer_id', 'o.customer_email'],
            ],
            'store' => [
                'label'    => new Maho\Db\Expr("COALESCE(cs.name, CONCAT('Store ', o.store_id))"),
                'link_id'  => 'o.store_id',
                'group_by' => ['o.store_id', 'cs.name'],
            ],
            'order_status' => [
                'label'    => 'o.status',
                'link_id'  => new Maho\Db\Expr('NULL'),
                'group_by' => 'o.status',
            ],
        };
    }

    // TODO(v1.1): implement real category join through catalog_category_product to get category_name.
    private function categoryExprs(Mage_Core_Model_Resource $r): array
    {
        // Resolved at staging - join through catalog_category_product to get category_name.
        // Stub returns product-keyed grouping for now; replace during integration.
        return [
            'label'    => 'oi.name',
            'link_id'  => 'oi.product_id',
            'group_by' => ['oi.product_id', 'oi.name'],
        ];
    }

    // TODO(v1.1): implement real brand join once brand attribute code is confirmed.
    private function brandExprs(Mage_Core_Model_Resource $r): array
    {
        // Same caveat as category - placeholder uses product. Replace during integration once
        // the brand attribute code is confirmed.
        return [
            'label'    => 'oi.name',
            'link_id'  => 'oi.product_id',
            'group_by' => ['oi.product_id', 'oi.name'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     * @return array<int, array<string, mixed>>
     */
    public function shapeRows(array $rawRows, string $dimension): array
    {
        $shaped = [];
        $linkRoute = match ($dimension) {
            'product', 'sku', 'category', 'brand' => 'adminhtml/catalog_product/edit',
            'customer'                             => 'adminhtml/customer/edit',
            'store'                                => 'adminhtml/system_store/editStore',
            default                                => null,
        };
        $linkParam  = $dimension === 'store' ? 'store_id' : 'id';
        $metricKeys = ['qty_sold', 'revenue', 'net_revenue', 'order_count', 'aov', 'margin'];
        foreach ($rawRows as $row) {
            $linkId = isset($row['link_id']) ? (int) $row['link_id'] : null;
            $entry  = [
                'label'   => (string) $row['label'],
                'value'   => $this->castNumeric($row['value']),
                'link_id' => $linkId,
            ];
            foreach ($metricKeys as $mk) {
                if (array_key_exists($mk, $row)) {
                    $entry[$mk] = $this->castNumeric($row[$mk]);
                }
            }
            if ($linkRoute && $linkId) {
                $entry['link_url'] = $this->buildAdminUrl($linkRoute, [$linkParam => $linkId]);
            }
            $shaped[] = $entry;
        }
        return $shaped;
    }

    /**
     * Return up to 100 contributing order_item rows for the given result row.
     * Returns null for order_status dimension (no link_id to join on).
     *
     * @param array<string, mixed> $args
     * @param int[]                $scopeStoreIds
     * @param array<string, mixed> $rowKey  expects keys: link_id (int|null), label (string)
     * @return array<int, array<string, mixed>>|null
     */
    public function drill(array $args, array $scopeStoreIds, array $rowKey): ?array
    {
        $dimension = $args['dimension'] ?? '';

        // order_status has no link_id - drilldown not supported.
        if ($dimension === 'order_status') {
            return null;
        }

        $linkId = isset($rowKey['link_id']) && $rowKey['link_id'] !== null
            ? (int) $rowKey['link_id']
            : null;

        if ($linkId === null && $dimension !== 'customer') {
            return null;
        }

        $conn   = Mage::getSingleton('core/resource')->getConnection('core_read');
        $r      = Mage::getSingleton('core/resource');
        $period = Mage::helper('aireports')->newPeriodNormalizer()->resolve($args['period']);

        return $this->buildDrillRows($conn, $r, $dimension, $linkId, $scopeStoreIds, $period);
    }

    /**
     * @param int[] $scopeStoreIds
     * @param array{from:string,to:string} $period
     * @return array<int, array<string, mixed>>
     */
    private function buildDrillRows(
        Maho\Db\Adapter\AdapterInterface $conn,
        Mage_Core_Model_Resource $r,
        string $dimension,
        ?int $linkId,
        array $scopeStoreIds,
        array $period,
    ): array {
        $orderItem = $r->getTableName('sales/order_item');
        $order     = $r->getTableName('sales/order');

        $select = $conn->select()
            ->from(['oi' => $orderItem], [])
            ->joinInner(['o' => $order], 'o.entity_id = oi.order_id', [])
            ->where('o.created_at >= ?', $period['from'])
            ->where('o.created_at <= ?', $period['to'])
            ->where('o.state NOT IN (?)', ['canceled', 'closed'])
            ->order('o.created_at DESC')
            ->limit(100);

        if (!empty($scopeStoreIds)) {
            $select->where('o.store_id IN (?)', $scopeStoreIds);
        }

        switch ($dimension) {
            case 'product':
            case 'sku':
            case 'category':
            case 'brand':
                $select
                    ->columns([
                        'order_increment_id' => 'o.increment_id',
                        'customer_email'     => 'o.customer_email',
                        'qty_ordered'        => 'oi.qty_ordered',
                        'row_total'          => new Maho\Db\Expr('oi.row_total - oi.discount_amount'),
                        'created_at'         => 'o.created_at',
                    ])
                    ->where('oi.product_id = ?', $linkId);
                break;

            case 'customer':
                if ($linkId !== null) {
                    $select->where('o.customer_id = ?', $linkId);
                } else {
                    $select->where('o.customer_id IS NULL');
                }
                $select->columns([
                    'order_increment_id' => 'o.increment_id',
                    'sku'                => 'oi.sku',
                    'qty_ordered'        => 'oi.qty_ordered',
                    'row_total'          => new Maho\Db\Expr('oi.row_total - oi.discount_amount'),
                    'created_at'         => 'o.created_at',
                ]);
                break;

            case 'store':
                $select
                    ->columns([
                        'order_increment_id' => 'o.increment_id',
                        'customer_email'     => 'o.customer_email',
                        'sku'                => 'oi.sku',
                        'qty_ordered'        => 'oi.qty_ordered',
                        'row_total'          => new Maho\Db\Expr('oi.row_total - oi.discount_amount'),
                        'created_at'         => 'o.created_at',
                    ])
                    ->where('o.store_id = ?', $linkId);
                break;

            default:
                return [];
        }

        $raw = $conn->fetchAll($select);
        return array_map(function (array $row): array {
            foreach (['qty_ordered', 'row_total'] as $k) {
                if (array_key_exists($k, $row)) {
                    $row[$k] = is_numeric($row[$k])
                        ? ((float) $row[$k] === floor((float) $row[$k]) ? (int) $row[$k] : (float) $row[$k])
                        : $row[$k];
                }
            }
            return $row;
        }, $raw);
    }

    private function castNumeric(mixed $raw): int|float
    {
        if (!is_numeric($raw)) {
            return 0;
        }
        $float = (float) $raw;
        return ($float === floor($float)) ? (int) $float : $float;
    }
}
