<?php

declare(strict_types=1);

class MageAustralia_AiReports_Model_Primitive_LowStock
    implements MageAustralia_AiReports_Model_PrimitiveInterface
{
    public function getName(): string { return 'low_stock'; }

    public function getDescription(): string
    {
        return 'Returns products whose days-of-cover (qty on hand / daily sales velocity over a lookback window) ' .
               'is below threshold_days. Use for "low stock alerts", "what needs reordering", "running out of X".';
    }

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

    public function getDefaultRender(): array
    {
        return ['primary' => 'table'];
    }

    public function execute(array $args, array $scopeStoreIds): array
    {
        $svv = new MageAustralia_AiReports_Model_Primitive_StockVsVelocity();
        $svvArgs = [
            'lookback_days'  => $args['lookback_days'] ?? 30,
            'product_filter' => $args['product_filter'] ?? ['type' => 'top_n_sellers', 'n' => 200, 'period' => ['type' => 'relative', 'value' => 'last_30_days']],
            'store_ids'      => $args['store_ids'] ?? null,
        ];
        $rawRows = $svv->execute($svvArgs, $scopeStoreIds);
        // Convert shaped rows back into the input shape shapeRows() expects.
        $reshaped = array_map(fn ($r) => [
            'sku' => $r['sku'], 'label' => $r['label'], 'product_id' => $r['link_id'],
            'qty_on_hand' => $r['qty_on_hand'], 'qty_sold' => $r['daily_velocity'] * ($svvArgs['lookback_days']),
            'lookback_days' => $svvArgs['lookback_days'],
        ], $rawRows);
        return $this->shapeRows($reshaped, thresholdDays: (int) $args['threshold_days']);
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     * @return array<int, array<string, mixed>>
     */
    public function shapeRows(array $rawRows, int $thresholdDays): array
    {
        $svv = new MageAustralia_AiReports_Model_Primitive_StockVsVelocity();
        $shaped = $svv->shapeRows($rawRows);
        $filtered = array_values(array_filter($shaped, fn ($r) =>
            $r['days_of_cover'] !== null && $r['days_of_cover'] <= $thresholdDays
        ));
        usort($filtered, fn ($a, $b) => $a['days_of_cover'] <=> $b['days_of_cover']);
        return $filtered;
    }
}
