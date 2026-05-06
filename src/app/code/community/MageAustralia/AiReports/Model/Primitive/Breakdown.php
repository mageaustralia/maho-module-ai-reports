<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_Primitive_Breakdown
    implements MageAustralia_AiReports_Model_PrimitiveInterface
{
    use MageAustralia_AiReports_Model_Primitive_UrlBuilderTrait;
    public function getName(): string { return 'breakdown'; }

    public function getDescription(): string
    {
        return 'Returns the share of a metric across a dimension over a period (pie chart). ' .
               'Use for "revenue by category", "orders by status", "sales by store".';
    }

    public function getArgsSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['metric', 'dimension', 'period'],
            'additionalProperties' => false,
            'properties'           => [
                'metric'    => ['type' => 'string', 'enum' => ['qty_sold', 'revenue', 'order_count']],
                'dimension' => ['type' => 'string', 'enum' => ['product', 'store', 'order_status']],
                'period'    => MageAustralia_AiReports_Model_PeriodNormalizer::schema(),
                'store_ids' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
            ],
        ];
    }

    public function getDefaultRender(): array
    {
        return ['primary' => 'pie_chart', 'secondary' => 'table'];
    }

    public function execute(array $args, array $scopeStoreIds): array
    {
        $conn   = Mage::getSingleton('core/resource')->getConnection('core_read');
        $r      = Mage::getSingleton('core/resource');
        $period = (new MageAustralia_AiReports_Model_PeriodNormalizer())->resolve($args['period']);

        $topN   = new MageAustralia_AiReports_Model_Primitive_TopN();
        $select = $topN->buildSelect($conn, $r, $args + ['limit' => 100], $scopeStoreIds, $period);
        return $this->shapeRows($conn->fetchAll($select), $args['dimension']);
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
            'product', 'category', 'brand' => 'adminhtml/catalog_product/edit',
            'store'                         => 'adminhtml/system_store/editStore',
            default                          => null,
        };
        $linkParam = $dimension === 'store' ? 'store_id' : 'id';
        $shaped = [];
        foreach ($rawRows as $row) {
            $val   = (float) $row['value'];
            $entry = [
                'label'     => (string) $row['label'],
                'value'     => $val,
                'share_pct' => $total > 0 ? round(($val / $total) * 100.0, 2) : 0.0,
                'link_id'   => isset($row['link_id']) && $row['link_id'] !== null ? (int) $row['link_id'] : null,
            ];
            if ($linkRoute && $entry['link_id']) {
                $entry['link_url'] = $this->buildAdminUrl($linkRoute, [$linkParam => $entry['link_id']]);
            }
            $shaped[] = $entry;
        }
        return $shaped;
    }
}
