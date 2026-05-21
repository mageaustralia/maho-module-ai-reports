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
    #[\Override]
    public function getName(): string { return 'top_n'; }

    #[\Override]
    public function getDescription(): string
    {
        return 'Returns the top N records ranked by a metric over a period, optionally filtered by store. ' .
               'Use when the user asks "top sellers", "best customers", "highest-revenue categories", etc.';
    }

    #[\Override]
    public function getArgsSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['metric', 'dimension', 'period', 'limit'],
            'additionalProperties' => false,
            'properties'           => [
                'metric'    => ['type' => 'string', 'enum' => ['qty_sold', 'revenue', 'net_revenue', 'order_count', 'aov', 'margin']],
                'dimension' => ['type' => 'string', 'enum' => ['product', 'sku', 'category', 'brand', 'customer', 'store', 'order_status']],
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

    #[\Override]
    public function getDefaultRender(): array
    {
        return ['primary' => 'bar_chart', 'secondary' => 'table'];
    }

    #[\Override]
    public function execute(array $args, array $scopeStoreIds): array
    {
        $conn   = Mage::getSingleton('core/resource')->getConnection('core_read');
        $r      = Mage::getSingleton('core/resource');
        $period = Mage::helper('aireports')->newPeriodNormalizer()->resolve($args['period']);
        $select = $this->buildSelect($conn, $r, $args, $scopeStoreIds, $period);
        return $this->shapeRows($conn->fetchAll($select), $args['dimension']);
    }

    /**
     * Public so the Growth primitive can re-use the same projection with
     * period-shifted args (compares period A vs period B). Not part of a
     * stable public API beyond AiReports — callers outside this module
     * should not rely on the signature.
     */
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
            'net_revenue' => 'SUM(o.base_total_invoiced - o.base_total_refunded)',
            'order_count' => 'COUNT(DISTINCT o.entity_id)',
            'aov'         => 'SUM(o.grand_total) / NULLIF(COUNT(DISTINCT o.entity_id), 0)',
            'margin'      => 'SUM(oi.row_total - oi.discount_amount - (oi.qty_ordered * oi.base_cost))',
        ];

        $dimensionExprs = $this->dimensionExprs($r, $args['dimension']);

        // Item-level metrics aggregate order_item rows; order-level metrics work off the
        // order header. Joining order_item when only order-level metrics are requested
        // multiplies each order by its line-item count and inflates the totals.
        $itemLevelMetrics    = ['qty_sold', 'revenue', 'margin'];
        $itemLevelDimensions = ['product', 'sku', 'category', 'brand'];
        $extras              = $args['display_metrics'] ?? [];

        // When grouping by an item-level dimension, the order_item join repeats each
        // order's header columns once per line, so order-level money columns
        // (base_total_invoiced, grand_total) get counted N times — a "revenue by
        // brand" would sum to a multiple of the real period total. Use per-line
        // base columns instead so the metric decomposes correctly across the
        // dimension. net_revenue becomes the line-item net (sales - discount -
        // refunds); shipping/tax live only on the order header and can't be
        // attributed to a brand/category, so they're excluded by design.
        // order_count stays COUNT(DISTINCT) which is unaffected by the fan-out.
        if (in_array($args['dimension'], $itemLevelDimensions, true)) {
            $itemNet = 'SUM(oi.base_row_total - oi.base_discount_amount - oi.base_amount_refunded)';
            $valueExprs['net_revenue'] = $itemNet;
            $valueExprs['aov']         = $itemNet . ' / NULLIF(COUNT(DISTINCT o.entity_id), 0)';
        }

        $needsItemTable =
            in_array($args['metric'], $itemLevelMetrics, true)
            || !empty(array_intersect($extras, $itemLevelMetrics))
            || in_array($args['dimension'], $itemLevelDimensions, true)
            || !empty($args['product_ids']);

        $select = $conn->select();
        if ($needsItemTable) {
            $select->from(['oi' => $orderItem], [])
                   ->joinInner(['o' => $order], 'o.entity_id = oi.order_id', []);
        } else {
            $select->from(['o' => $order], []);
        }

        if ($args['dimension'] === 'store') {
            $select->joinLeft(['cs' => $r->getTableName('core/store')], 'cs.store_id = o.store_id', []);
        }

        // Dimensions like category/brand need extra joins (declared by dimensionExprs).
        foreach ($dimensionExprs['joins'] ?? [] as $join) {
            if (($join['type'] ?? 'inner') === 'left') {
                $select->joinLeft($join['name'], $join['cond'], []);
            } else {
                $select->joinInner($join['name'], $join['cond'], []);
            }
        }

        $columns = [
            'label'   => $dimensionExprs['label'],
            'link_id' => $dimensionExprs['link_id'],
            'value'   => new Maho\Db\Expr($valueExprs[$args['metric']]),
        ];

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

    /** @return array{label: string|Maho\Db\Expr, link_id: string|Maho\Db\Expr, group_by: string|array<int,string>, joins?: array<int, array{type: string, name: array<string, string>, cond: string}>} */
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
            default => throw new InvalidArgumentException("Unsupported dimension: {$dimension}"),
        };
    }

    /**
     * Sales by category. A product can belong to multiple categories, so each
     * order line is counted under every (non-root) category it sits in — shares
     * can sum to >100%, which matches how merchants read "how much did X sell".
     * Root (level 0) and store-root (level 1) categories are excluded.
     */
    private function categoryExprs(Mage_Core_Model_Resource $r): array
    {
        $nameAttrId = (int) Mage::getSingleton('eav/config')
            ->getAttribute('catalog_category', 'name')->getId();
        $varcharTable = $r->getTableName('catalog/category') . '_varchar';

        return [
            'label'    => new Maho\Db\Expr("COALESCE(ccev.value, CONCAT('Category ', ccp.category_id))"),
            'link_id'  => 'ccp.category_id',
            'group_by' => ['ccp.category_id', 'ccev.value'],
            'joins'    => [
                [
                    'type' => 'inner',
                    'name' => ['ccp' => $r->getTableName('catalog/category_product')],
                    'cond' => 'ccp.product_id = oi.product_id',
                ],
                [
                    'type' => 'inner',
                    'name' => ['cce' => $r->getTableName('catalog/category')],
                    'cond' => 'cce.entity_id = ccp.category_id AND cce.level > 1',
                ],
                [
                    'type' => 'left',
                    'name' => ['ccev' => $varcharTable],
                    'cond' => "ccev.entity_id = ccp.category_id AND ccev.store_id = 0 AND ccev.attribute_id = {$nameAttrId}",
                ],
            ],
        ];
    }

    /**
     * Sales by brand. Brand attribute is admin-configurable with auto-detection
     * (brand_id / brand / manufacturer). Select-type attributes resolve the label
     * via eav_attribute_option_value; text attributes use the stored value.
     */
    private function brandExprs(Mage_Core_Model_Resource $r): array
    {
        $attr = Mage::helper('aireports')->getBrandAttribute();
        if ($attr === null) {
            throw new InvalidArgumentException(
                'Sales by brand is unavailable: no brand attribute is configured or detected. '
                . 'Set one under System > Configuration > Maho AI: Reports.',
            );
        }

        $attrId     = (int) $attr['id'];
        $valueTable = $r->getTableName('catalog/product') . '_' . $attr['backend_type'];
        $isOption   = $attr['backend_type'] === 'int'
            && in_array($attr['frontend_input'], ['select', 'multiselect'], true);

        if ($isOption) {
            return [
                'label'    => new Maho\Db\Expr("COALESCE(NULLIF(eaov.value, ''), CONCAT('Brand ', cpb.value))"),
                'link_id'  => 'cpb.value',
                'group_by' => ['cpb.value', 'eaov.value'],
                'joins'    => [
                    [
                        'type' => 'inner',
                        'name' => ['cpb' => $valueTable],
                        'cond' => "cpb.entity_id = oi.product_id AND cpb.store_id = 0 AND cpb.attribute_id = {$attrId}",
                    ],
                    [
                        'type' => 'left',
                        'name' => ['eaov' => $r->getTableName('eav/attribute_option_value')],
                        'cond' => 'eaov.option_id = cpb.value AND eaov.store_id = 0',
                    ],
                ],
            ];
        }

        // Text/varchar brand attribute — value is the brand name itself.
        return [
            'label'    => new Maho\Db\Expr("COALESCE(NULLIF(cpb.value, ''), 'Unknown')"),
            'link_id'  => new Maho\Db\Expr('NULL'),
            'group_by' => ['cpb.value'],
            'joins'    => [
                [
                    'type' => 'inner',
                    'name' => ['cpb' => $valueTable],
                    'cond' => "cpb.entity_id = oi.product_id AND cpb.store_id = 0 AND cpb.attribute_id = {$attrId}",
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     * @return array<int, array<string, mixed>>
     */
    public function shapeRows(array $rawRows, string $dimension): array
    {
        $shaped = [];
        // brand + order_status have no single edit page (link_id is still kept
        // for drilldown, but no link_url is emitted).
        $linkRoute = match ($dimension) {
            'product', 'sku' => 'adminhtml/catalog_product/edit',
            'category'       => 'adminhtml/catalog_category/edit',
            'customer'       => 'adminhtml/customer/edit',
            'store'          => 'adminhtml/system_store/editStore',
            default          => null,
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

    #[\Override]
    public function supportsDrilldown(): bool
    {
        return true;
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
    #[\Override]
    public function drill(array $args, array $scopeStoreIds, array $rowKey): ?array
    {
        $dimension = $args['dimension'] ?? '';

        // order_status has no link_id - drilldown not supported.
        if ($dimension === 'order_status') {
            return null;
        }

        $linkId = isset($rowKey['link_id']) ? (int) $rowKey['link_id'] : null;

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

        // Pass the IANA tz name (not a fixed offset captured "now") so MySQL applies
        // the right offset per-row across DST boundaries. Requires `mysql_tzinfo_to_sql`
        // to have been loaded; if it hasn't, CONVERT_TZ returns NULL and the columns
        // come through as NULL, which is more visible than a silent hour-shift.
        $storeTz        = Mage::helper('aireports')->getStoreTimezone();
        $createdAtLocal = new Maho\Db\Expr(
            $conn->quoteInto("CONVERT_TZ(o.created_at, 'UTC', ?)", $storeTz),
        );

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

        // For dimensions where the drill returns all items in matching orders (store, customer),
        // hide the simple-child rows of configurable/bundle parents so we don't double-list each
        // line. For product/sku drills the explicit product_id filter already picks one side.
        $hideChildren = in_array($dimension, ['store', 'customer'], true);

        switch ($dimension) {
            case 'product':
            case 'sku':
            case 'category':
            case 'brand':
                $select->columns([
                    'order_id'           => 'o.entity_id',
                    'order_increment_id' => 'o.increment_id',
                    'customer_email'     => 'o.customer_email',
                    'qty_ordered'        => 'oi.qty_ordered',
                    'row_total'          => new Maho\Db\Expr('oi.row_total - oi.discount_amount'),
                    'created_at'         => $createdAtLocal,
                ]);
                if ($dimension === 'category') {
                    // link_id is a category_id — match items whose product is in it.
                    $productsInCategory = $conn->select()
                        ->from($r->getTableName('catalog/category_product'), ['product_id'])
                        ->where('category_id = ?', $linkId);
                    $select->where('oi.product_id IN (?)', $productsInCategory);
                } elseif ($dimension === 'brand') {
                    // link_id is a brand option_id — match items whose product carries it.
                    $brand = Mage::helper('aireports')->getBrandAttribute();
                    if ($brand === null) {
                        return [];
                    }
                    $valueTable = $r->getTableName('catalog/product') . '_' . $brand['backend_type'];
                    $productsWithBrand = $conn->select()
                        ->from($valueTable, ['entity_id'])
                        ->where('attribute_id = ?', (int) $brand['id'])
                        ->where('store_id = ?', 0)
                        ->where('value = ?', $linkId);
                    $select->where('oi.product_id IN (?)', $productsWithBrand);
                } else {
                    $select->where('oi.product_id = ?', $linkId);
                }
                break;

            case 'customer':
                if ($linkId !== null) {
                    $select->where('o.customer_id = ?', $linkId);
                } else {
                    $select->where('o.customer_id IS NULL');
                }
                $select->columns([
                    'order_id'           => 'o.entity_id',
                    'order_increment_id' => 'o.increment_id',
                    'sku'                => 'oi.sku',
                    'qty_ordered'        => 'oi.qty_ordered',
                    'row_total'          => new Maho\Db\Expr('oi.row_total - oi.discount_amount'),
                    'created_at'         => $createdAtLocal,
                ]);
                break;

            case 'store':
                $select
                    ->columns([
                        'order_id'           => 'o.entity_id',
                        'order_increment_id' => 'o.increment_id',
                        'customer_email'     => 'o.customer_email',
                        'sku'                => 'oi.sku',
                        'qty_ordered'        => 'oi.qty_ordered',
                        'row_total'          => new Maho\Db\Expr('oi.row_total - oi.discount_amount'),
                        'created_at'         => $createdAtLocal,
                    ])
                    ->where('o.store_id = ?', $linkId);
                break;

            default:
                return [];
        }

        if ($hideChildren) {
            $select->where('oi.parent_item_id IS NULL');
        }

        $raw = $conn->fetchAll($select);
        return array_map(function (array $row): array {
            if (isset($row['order_id'])) {
                $row['__links'] = [
                    'order_increment_id' => $this->buildAdminUrl(
                        'adminhtml/sales_order/view',
                        ['order_id' => (int) $row['order_id']],
                    ),
                ];
                unset($row['order_id']);
            }
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
