<?php

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
                'metric'    => ['type' => 'string', 'enum' => ['qty_sold', 'revenue', 'order_count', 'aov', 'margin']],
                'dimension' => ['type' => 'string', 'enum' => ['product', 'sku', 'customer', 'store', 'order_status']],
                'period'    => ['type' => 'object'],
                'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                'store_ids' => [
                    'type'  => ['array', 'null'],
                    'items' => ['type' => 'integer'],
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
        $period = (new MageAustralia_AiReports_Model_PeriodNormalizer())->resolve($args['period']);
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

        $valueExpr = match ($args['metric']) {
            'qty_sold'    => 'SUM(oi.qty_ordered)',
            'revenue'     => 'SUM(oi.row_total - oi.discount_amount)',
            'order_count' => 'COUNT(DISTINCT o.entity_id)',
            'aov'         => 'SUM(o.grand_total) / NULLIF(COUNT(DISTINCT o.entity_id), 0)',
            'margin'      => 'SUM(oi.row_total - oi.discount_amount - (oi.qty_ordered * oi.base_cost))',
        };

        $dimensionExprs = $this->dimensionExprs($r, $args['dimension']);

        $select = $conn->select()
            ->from(['oi' => $orderItem], [])
            ->joinInner(['o' => $order], 'o.entity_id = oi.order_id', [])
            ->columns([
                'label'   => $dimensionExprs['label'],
                'link_id' => $dimensionExprs['link_id'],
                'value'   => new Maho\Db\Expr($valueExpr),
            ])
            ->where('o.created_at >= ?', $period['from'])
            ->where('o.created_at <= ?', $period['to'])
            ->where('o.state NOT IN (?)', ['canceled', 'closed'])
            ->group($dimensionExprs['group_by'])
            ->order(new Maho\Db\Expr('value DESC'))
            ->limit((int) $args['limit']);

        if (!empty($scopeStoreIds)) {
            $select->where('o.store_id IN (?)', $scopeStoreIds);
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
                'label'    => 'o.store_id',
                'link_id'  => 'o.store_id',
                'group_by' => 'o.store_id',
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
        $linkParam = $dimension === 'store' ? 'store_id' : 'id';
        foreach ($rawRows as $row) {
            $linkId = isset($row['link_id']) ? (int) $row['link_id'] : null;
            if (is_numeric($row['value'])) {
                $floatVal = (float) $row['value'];
                $value = ($floatVal === floor($floatVal)) ? (int) $floatVal : $floatVal;
            } else {
                $value = 0;
            }
            $entry = [
                'label'   => (string) $row['label'],
                'value'   => $value,
                'link_id' => $linkId,
            ];
            if ($linkRoute && $linkId) {
                $entry['link_url'] = $this->buildAdminUrl($linkRoute, [$linkParam => $linkId]);
            }
            $shaped[] = $entry;
        }
        return $shaped;
    }
}
