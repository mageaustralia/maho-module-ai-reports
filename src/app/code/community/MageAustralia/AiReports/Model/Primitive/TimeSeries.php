<?php

declare(strict_types=1);

class MageAustralia_AiReports_Model_Primitive_TimeSeries
    implements MageAustralia_AiReports_Model_PrimitiveInterface
{
    public function getName(): string { return 'time_series'; }

    public function getDescription(): string
    {
        return 'Returns a time-series of a metric over a period at day/week/month granularity. ' .
               'Optional comparison_period produces a second series aligned bucket-by-bucket on the same axis ' .
               '(e.g. compare "last_30_days" vs "year ago" to overlay this-period and prior-period trends). ' .
               'Use for "daily revenue", "weekly orders", trends, period-over-period comparisons.';
    }

    public function getArgsSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['metric', 'period', 'granularity'],
            'additionalProperties' => false,
            'properties'           => [
                'metric'            => ['type' => 'string', 'enum' => ['qty_sold', 'revenue', 'order_count', 'aov']],
                'period'            => MageAustralia_AiReports_Model_PeriodNormalizer::schema(),
                'comparison_period' => MageAustralia_AiReports_Model_PeriodNormalizer::schema(),
                'granularity'       => ['type' => 'string', 'enum' => ['day', 'week', 'month']],
                'group_by'          => ['type' => ['string', 'null'], 'enum' => ['product', 'store', null]],
                'store_ids'         => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
            ],
        ];
    }

    public function getDefaultRender(): array
    {
        return ['primary' => 'line_chart'];
    }

    public function execute(array $args, array $scopeStoreIds): array
    {
        $conn      = Mage::getSingleton('core/resource')->getConnection('core_read');
        $r         = Mage::getSingleton('core/resource');
        $normalizer = new MageAustralia_AiReports_Model_PeriodNormalizer();

        $primaryPeriod = $normalizer->resolve($args['period']);
        $primaryRows   = $this->fetchPeriodRows($conn, $r, $args, $scopeStoreIds, $primaryPeriod, 'Current');
        $primaryShaped = $this->shapeRows($primaryRows);

        if (empty($args['comparison_period'])) {
            return $primaryShaped;
        }

        $comparisonPeriod = $normalizer->resolve($args['comparison_period']);
        $comparisonRows   = $this->fetchPeriodRows($conn, $r, $args, $scopeStoreIds, $comparisonPeriod, 'Comparison');
        $comparisonShaped = $this->shapeRows($comparisonRows);

        $aligned = $this->alignByBucketIndex($primaryShaped, $comparisonShaped, $primaryPeriod, $comparisonPeriod);
        return array_merge($primaryShaped, $aligned);
    }

    /**
     * @param array<string, mixed> $args
     * @param int[]                $scopeStoreIds
     * @param array{from:string,to:string} $period
     * @return array<int, array<string, mixed>>
     */
    private function fetchPeriodRows(
        Maho\Db\Adapter\AdapterInterface $conn,
        Mage_Core_Model_Resource $r,
        array $args,
        array $scopeStoreIds,
        array $period,
        string $seriesLabel,
    ): array {
        $bucketExpr = match ($args['granularity']) {
            'day'   => 'DATE(o.created_at)',
            'week'  => 'DATE_SUB(DATE(o.created_at), INTERVAL WEEKDAY(o.created_at) DAY)',
            'month' => "DATE_FORMAT(o.created_at, '%Y-%m-01')",
        };

        $valueExpr = match ($args['metric']) {
            'qty_sold'    => 'SUM(oi.qty_ordered)',
            'revenue'     => 'SUM(oi.row_total - oi.discount_amount)',
            'order_count' => 'COUNT(DISTINCT o.entity_id)',
            'aov'         => 'SUM(o.grand_total) / NULLIF(COUNT(DISTINCT o.entity_id), 0)',
        };

        $select = $conn->select()
            ->from(['oi' => $r->getTableName('sales/order_item')], [])
            ->joinInner(['o' => $r->getTableName('sales/order')], 'o.entity_id = oi.order_id', [])
            ->columns([
                'date'         => new Maho\Db\Expr($bucketExpr),
                'series_label' => new Maho\Db\Expr($conn->quote($seriesLabel)),
                'value'        => new Maho\Db\Expr($valueExpr),
            ])
            ->where('o.created_at >= ?', $period['from'])
            ->where('o.created_at <= ?', $period['to'])
            ->where('o.state NOT IN (?)', ['canceled', 'closed'])
            ->group(new Maho\Db\Expr($bucketExpr))
            ->order('date ASC');

        if (!empty($scopeStoreIds)) {
            $select->where('o.store_id IN (?)', $scopeStoreIds);
        }

        return $conn->fetchAll($select);
    }

    /**
     * Re-key comparison rows so their dates align with the primary period's x-axis.
     * Each comparison bucket gets the date offset (from comparison_period start) and is
     * mapped to the corresponding date in the primary period. This way both series share
     * one x-axis on the chart instead of producing a wide gappy axis.
     *
     * @param array<int, array<string, mixed>> $primary
     * @param array<int, array<string, mixed>> $comparison
     * @param array{from:string,to:string} $primaryPeriod
     * @param array{from:string,to:string} $comparisonPeriod
     * @return array<int, array<string, mixed>>
     */
    public function alignByBucketIndex(array $primary, array $comparison, array $primaryPeriod, array $comparisonPeriod): array
    {
        if (empty($comparison)) {
            return [];
        }

        $primaryStart    = new \DateTimeImmutable(substr($primaryPeriod['from'], 0, 10));
        $comparisonStart = new \DateTimeImmutable(substr($comparisonPeriod['from'], 0, 10));

        $primaryDates = [];
        foreach ($primary as $row) {
            $primaryDates[$row['date']] = true;
        }

        $aligned = [];
        foreach ($comparison as $row) {
            $compareDate    = new \DateTimeImmutable($row['date']);
            $offsetDays     = (int) $compareDate->diff($comparisonStart)->format('%r%a');
            $offsetDaysAbs  = abs($offsetDays);
            $alignedDateObj = $primaryStart->modify("+$offsetDaysAbs days");
            $alignedDate    = $alignedDateObj->format('Y-m-d');

            $aligned[] = [
                'date'         => $alignedDate,
                'series_label' => $row['series_label'],
                'value'        => $row['value'],
            ];
        }

        return $aligned;
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     * @return array<int, array<string, mixed>>
     */
    public function shapeRows(array $rawRows): array
    {
        $shaped = [];
        foreach ($rawRows as $row) {
            $shaped[] = [
                'date'         => (string) $row['date'],
                'series_label' => (string) $row['series_label'],
                'value'        => (float) $row['value'],
            ];
        }
        return $shaped;
    }
}
