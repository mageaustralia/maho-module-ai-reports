<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_Primitive_Growth
    implements MageAustralia_AiReports_Model_PrimitiveInterface
{
    use MageAustralia_AiReports_Model_Primitive_UrlBuilderTrait;
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
                'metric'    => ['type' => 'string', 'enum' => ['qty_sold', 'revenue', 'net_revenue', 'order_count', 'aov', 'margin']],
                'dimension' => ['type' => 'string', 'enum' => ['product', 'sku', 'customer', 'store']],
                'period_a'  => MageAustralia_AiReports_Model_PeriodNormalizer::schema(),
                'period_b'  => MageAustralia_AiReports_Model_PeriodNormalizer::schema(),
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
        $norm   = Mage::helper('aireports')->newPeriodNormalizer();
        $a      = $norm->resolve($args['period_a']);
        $b      = $norm->resolve($args['period_b']);

        $topN  = new MageAustralia_AiReports_Model_Primitive_TopN();
        $argsA = ['metric' => $args['metric'], 'dimension' => $args['dimension'], 'period' => $args['period_a'], 'limit' => max(50, $args['limit'] * 3)];
        $argsB = ['metric' => $args['metric'], 'dimension' => $args['dimension'], 'period' => $args['period_b'], 'limit' => max(50, $args['limit'] * 3)];
        $rowsA = $conn->fetchAll($topN->buildSelect($conn, $r, $argsA, $scopeStoreIds, $a));
        $rowsB = $conn->fetchAll($topN->buildSelect($conn, $r, $argsB, $scopeStoreIds, $b));

        $merged = [];
        foreach ($rowsA as $row) {
            $key = $row['link_id'] !== null ? 'id:' . $row['link_id'] : 'label:' . $row['label'];
            $merged[$key] = ['label' => $row['label'], 'link_id' => $row['link_id'] ?? null, 'value_a' => $row['value'], 'value_b' => 0];
        }
        foreach ($rowsB as $row) {
            $key = $row['link_id'] !== null ? 'id:' . $row['link_id'] : 'label:' . $row['label'];
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
        $linkRoute = match ($dimension) {
            'product', 'sku', 'category', 'brand' => 'adminhtml/catalog_product/edit',
            'customer'                             => 'adminhtml/customer/edit',
            'store'                                => 'adminhtml/system_store/editStore',
            default                                => null,
        };
        $linkParam = $dimension === 'store' ? 'store_id' : 'id';

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
            if ($linkRoute && $entry['link_id']) {
                $entry['link_url'] = $this->buildAdminUrl($linkRoute, [$linkParam => $entry['link_id']]);
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

    /**
     * Return up to 100 contributing order_item rows for the given result row,
     * tagged with a 'period' column ('A' or 'B') so the sub-table can show
     * which period each contributor came from.
     *
     * @param array<string, mixed> $args
     * @param int[]                $scopeStoreIds
     * @param array<string, mixed> $rowKey  expects keys: link_id (int|null), label (string)
     * @return array<int, array<string, mixed>>|null
     */
    public function drill(array $args, array $scopeStoreIds, array $rowKey): ?array
    {
        $dimension = $args['dimension'] ?? '';

        // order_status dimension is not in Growth's schema but guard anyway.
        if ($dimension === 'order_status') {
            return null;
        }

        $linkId = isset($rowKey['link_id']) && $rowKey['link_id'] !== null
            ? (int) $rowKey['link_id']
            : null;

        if ($linkId === null && $dimension !== 'customer') {
            return null;
        }

        $conn = Mage::getSingleton('core/resource')->getConnection('core_read');
        $r    = Mage::getSingleton('core/resource');
        $norm = Mage::helper('aireports')->newPeriodNormalizer();
        $a    = $norm->resolve($args['period_a']);
        $b    = $norm->resolve($args['period_b']);

        $rowsA = $this->fetchDrillRows($conn, $r, $dimension, $linkId, $scopeStoreIds, $a, 'A');
        $rowsB = $this->fetchDrillRows($conn, $r, $dimension, $linkId, $scopeStoreIds, $b, 'B');

        // Merge and sort most-recent first, capped at 100 total.
        $merged = array_merge($rowsA, $rowsB);
        usort($merged, function (array $x, array $y): int {
            return strcmp((string) $y['created_at'], (string) $x['created_at']);
        });

        return array_slice($merged, 0, 100);
    }

    /**
     * @param int[]                        $scopeStoreIds
     * @param array{from:string,to:string} $period
     * @return array<int, array<string, mixed>>
     */
    private function fetchDrillRows(
        Maho\Db\Adapter\AdapterInterface $conn,
        Mage_Core_Model_Resource $r,
        string $dimension,
        ?int $linkId,
        array $scopeStoreIds,
        array $period,
        string $periodLabel,
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
            ->limit(50); // 50 per period; merged total capped at 100 in drill()

        if (!empty($scopeStoreIds)) {
            $select->where('o.store_id IN (?)', $scopeStoreIds);
        }

        $periodExpr = new Maho\Db\Expr($conn->quote($periodLabel));

        switch ($dimension) {
            case 'product':
            case 'sku':
            case 'category':
            case 'brand':
                $select
                    ->columns([
                        'period'             => $periodExpr,
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
                    'period'             => $periodExpr,
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
                        'period'             => $periodExpr,
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
                if (array_key_exists($k, $row) && is_numeric($row[$k])) {
                    $f = (float) $row[$k];
                    $row[$k] = ($f === floor($f)) ? (int) $f : $f;
                }
            }
            return $row;
        }, $raw);
    }
}
