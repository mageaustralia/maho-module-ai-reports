<?php

declare(strict_types=1);

class MageAustralia_AiReports_Model_Primitive_Growth
    implements MageAustralia_AiReports_Model_PrimitiveInterface
{
    public function getName(): string { return 'growth'; }

    public function getDescription(): string
    {
        return 'Compares a metric across two periods (period_a vs period_b) and returns the top movers ' .
               'sorted by percentage change. Use for "biggest growth", "fastest growing", "biggest declines".';
    }

    public function getArgsSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['metric', 'dimension', 'period_a', 'period_b', 'limit'],
            'additionalProperties' => false,
            'properties' => [
                'metric'    => ['type' => 'string', 'enum' => ['qty_sold', 'revenue', 'order_count', 'aov', 'margin']],
                'dimension' => ['type' => 'string', 'enum' => ['product', 'sku', 'category', 'brand', 'customer', 'store']],
                'period_a'  => ['type' => 'object'],
                'period_b'  => ['type' => 'object'],
                'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                'store_ids' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
            ],
        ];
    }

    public function getDefaultRender(): array
    {
        return ['primary' => 'table'];
    }

    public function execute(array $args, array $scopeStoreIds): array
    {
        $conn   = Mage::getSingleton('core/resource')->getConnection('core_read');
        $r      = Mage::getSingleton('core/resource');
        $norm   = new MageAustralia_AiReports_Model_PeriodNormalizer();
        $a      = $norm->resolve($args['period_a']);
        $b      = $norm->resolve($args['period_b']);

        $topN  = new MageAustralia_AiReports_Model_Primitive_TopN();
        $argsA = ['metric' => $args['metric'], 'dimension' => $args['dimension'], 'period' => $args['period_a'], 'limit' => max(50, $args['limit'] * 3)];
        $argsB = ['metric' => $args['metric'], 'dimension' => $args['dimension'], 'period' => $args['period_b'], 'limit' => max(50, $args['limit'] * 3)];
        $rowsA = $conn->fetchAll($topN->buildSelect($conn, $r, $argsA, $scopeStoreIds, $a));
        $rowsB = $conn->fetchAll($topN->buildSelect($conn, $r, $argsB, $scopeStoreIds, $b));

        $merged = [];
        foreach ($rowsA as $row) {
            $key = $row['link_id'] ?? $row['label'];
            $merged[$key] = ['label' => $row['label'], 'link_id' => $row['link_id'] ?? null, 'value_a' => $row['value'], 'value_b' => 0];
        }
        foreach ($rowsB as $row) {
            $key = $row['link_id'] ?? $row['label'];
            if (!isset($merged[$key])) {
                $merged[$key] = ['label' => $row['label'], 'link_id' => $row['link_id'] ?? null, 'value_a' => 0, 'value_b' => 0];
            }
            $merged[$key]['value_b'] = $row['value'];
        }

        $shaped = $this->shapeRows(array_values($merged), $args['dimension']);
        return array_slice($shaped, 0, (int) $args['limit']);
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows  expects keys label, link_id, value_a, value_b
     * @return array<int, array<string, mixed>>
     */
    public function shapeRows(array $rawRows, string $dimension): array
    {
        $linkBase = match ($dimension) {
            'product', 'sku', 'category', 'brand' => 'catalog_product/edit/id/',
            'customer'                             => 'customer/edit/id/',
            'store'                                => 'system_store/editStore/store_id/',
            default                                => null,
        };

        $shaped = [];
        foreach ($rawRows as $row) {
            $a        = (float) $row['value_a'];
            $b        = (float) $row['value_b'];
            $deltaAbs = $b - $a;
            $deltaPct = ($a == 0.0) ? null : round(($deltaAbs / $a) * 100.0, 2);

            $entry = [
                'label'     => (string) $row['label'],
                'link_id'   => isset($row['link_id']) && $row['link_id'] !== null ? (int) $row['link_id'] : null,
                'value_a'   => $a,
                'value_b'   => $b,
                'delta_abs' => $deltaAbs,
                'delta_pct' => $deltaPct,
            ];
            if ($linkBase && $entry['link_id']) {
                $entry['link_url'] = '/admin/' . $linkBase . $entry['link_id'];
            }
            $shaped[] = $entry;
        }

        usort($shaped, function ($x, $y) {
            $xp = $x['delta_pct'] ?? -INF;
            $yp = $y['delta_pct'] ?? -INF;
            return $yp <=> $xp;
        });

        return $shaped;
    }
}
