<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_Primitive_LowStock implements MageAustralia_AiReports_Model_PrimitiveInterface
{
    #[\Override]
    public function getName(): string
    {
        return 'low_stock';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Returns products whose days-of-cover (qty on hand / daily sales velocity over a lookback window) ' .
               'is below threshold_days. Use for "low stock alerts", "what needs reordering", "running out of X".';
    }

    #[\Override]
    public function getArgsSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['threshold_days'],
            'additionalProperties' => false,
            'properties' => [
                'threshold_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
                'lookback_days'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 730],
                'product_filter' => [
                    'type'  => ['object', 'null'],
                ],
                'store_ids'      => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
            ],
        ];
    }

    #[\Override]
    public function getDefaultRender(): array
    {
        return ['primary' => 'table'];
    }

    /**
     * Drilldown is not applicable to low_stock (single-value series, not record aggregations).
     */
    #[\Override]
    public function drill(array $args, array $scopeStoreIds, array $rowKey): ?array
    {
        return null;
    }

    #[\Override]
    public function supportsDrilldown(): bool
    {
        return false;
    }

    #[\Override]
    public function execute(array $args, array $scopeStoreIds): array
    {
        $svv = new MageAustralia_AiReports_Model_Primitive_StockVsVelocity();
        $svvArgs = [
            'lookback_days'  => $args['lookback_days'] ?? 30,
            'product_filter' => $args['product_filter'] ?? ['type' => 'top_n_sellers', 'n' => 200, 'period' => ['type' => 'relative', 'value' => 'last_30_days']],
            'store_ids'      => $args['store_ids'] ?? null,
        ];
        // Use fetchRawRows to avoid the lossy round-trip through shapeRows + reverse.
        $rawRows = $svv->fetchRawRows($svvArgs, $scopeStoreIds);
        return $this->shapeRows($rawRows, thresholdDays: (int) $args['threshold_days']);
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     * @return array<int, array<string, mixed>>
     */
    public function shapeRows(array $rawRows, int $thresholdDays): array
    {
        $svv = new MageAustralia_AiReports_Model_Primitive_StockVsVelocity();
        $shaped = $svv->shapeRows($rawRows);
        $filtered = array_values(array_filter(
            $shaped,
            fn($r) =>
            $r['days_of_cover'] !== null && $r['days_of_cover'] <= $thresholdDays,
        ));
        usort($filtered, fn($a, $b) => $a['days_of_cover'] <=> $b['days_of_cover']);
        return $filtered;
    }
}
