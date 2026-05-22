<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_Primitive_Breakdown implements MageAustralia_AiReports_Model_PrimitiveInterface
{
    use MageAustralia_AiReports_Model_Primitive_UrlBuilderTrait;
    #[\Override]
    public function getName(): string
    {
        return 'breakdown';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Returns the share of a metric across a dimension over a period (pie chart). ' .
               'Use for "revenue by category", "orders by status", "sales by store".';
    }

    #[\Override]
    public function getArgsSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['metric', 'dimension', 'period'],
            'additionalProperties' => false,
            'properties'           => [
                'metric'    => ['type' => 'string', 'enum' => ['qty_sold', 'revenue', 'net_revenue', 'order_count', 'discount_total', 'tax_total', 'shipping_total']],
                'dimension' => ['type' => 'string', 'enum' => ['product', 'category', 'brand', 'store', 'order_status', 'payment_method', 'shipping_method', 'region', 'country', 'coupon_code']],
                'period'    => MageAustralia_AiReports_Model_PeriodNormalizer::schema(),
                'store_ids'   => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
                'product_ids' => [
                    'type'        => ['array', 'null'],
                    'items'       => ['type' => 'integer'],
                    'description' => 'Optional list of product IDs to filter results to (for queries about specific products).',
                ],
            ],
        ];
    }

    #[\Override]
    public function getDefaultRender(): array
    {
        return ['primary' => 'pie_chart', 'secondary' => 'table'];
    }

    #[\Override]
    public function execute(array $args, array $scopeStoreIds): array
    {
        $conn   = Mage::getSingleton('core/resource')->getConnection('core_read');
        $r      = Mage::getSingleton('core/resource');
        $period = Mage::helper('aireports')->newPeriodNormalizer()->resolve($args['period']);

        $topN   = new MageAustralia_AiReports_Model_Primitive_TopN();
        $select = $topN->buildSelect($conn, $r, $args + ['limit' => 100], $scopeStoreIds, $period);
        return $this->shapeRows($conn->fetchAll($select), $args['dimension']);
    }

    /**
     * Return up to 100 contributing order_item rows for the given result row.
     * Delegates to TopN's drill logic since Breakdown reuses the same base query.
     *
     * @param array<string, mixed> $args
     * @param int[]                $scopeStoreIds
     * @param array<string, mixed> $rowKey  expects keys: link_id (int|null), label (string)
     * @return array<int, array<string, mixed>>|null
     */
    #[\Override]
    public function drill(array $args, array $scopeStoreIds, array $rowKey): ?array
    {
        // order_status has no link_id - drilldown not supported.
        if (($args['dimension'] ?? '') === 'order_status') {
            return null;
        }

        // Breakdown shares the same dimension logic as TopN; delegate.
        $topN = new MageAustralia_AiReports_Model_Primitive_TopN();
        return $topN->drill($args, $scopeStoreIds, $rowKey);
    }

    #[\Override]
    public function supportsDrilldown(): bool
    {
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows  rows with label, link_id, value
     * @return array<int, array<string, mixed>>
     */
    public function shapeRows(array $rawRows, string $dimension): array
    {
        $total = 0.0;
        foreach ($rawRows as $row) {
            $total += (float) $row['value'];
        }
        $linkRoute = match ($dimension) {
            'product'  => 'adminhtml/catalog_product/edit',
            'category' => 'adminhtml/catalog_category/edit',
            'store'    => 'adminhtml/system_store/editStore',
            default    => null,
        };
        $linkParam = $dimension === 'store' ? 'store_id' : 'id';
        $shaped = [];
        foreach ($rawRows as $row) {
            $val   = (float) $row['value'];
            $entry = [
                'label'     => (string) $row['label'],
                'value'     => $val,
                'share_pct' => $total > 0 ? round(($val / $total) * 100.0, 2) : 0.0,
                'link_id'   => isset($row['link_id']) ? (int) $row['link_id'] : null,
            ];
            if ($linkRoute && $entry['link_id']) {
                $entry['link_url'] = $this->buildAdminUrl($linkRoute, [$linkParam => $entry['link_id']]);
            }
            $shaped[] = $entry;
        }
        return $shaped;
    }
}
