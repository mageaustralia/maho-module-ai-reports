<?php

declare(strict_types=1);

class MageAustralia_AiReports_Model_Primitive_TimeSeries
    implements MageAustralia_AiReports_Model_PrimitiveInterface
{
    public function getName(): string { return 'time_series'; }

    public function getDescription(): string
    {
        return 'Returns a time-series of a metric over a period at day/week/month granularity. ' .
               'Optional group_by dimension produces multiple series. Use for "daily revenue", "weekly orders", trends.';
    }

    public function getArgsSchema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['metric', 'period', 'granularity'],
            'additionalProperties' => false,
            'properties'           => [
                'metric'      => ['type' => 'string', 'enum' => ['qty_sold', 'revenue', 'order_count', 'aov']],
                'period'      => ['type' => 'object'],
                'granularity' => ['type' => 'string', 'enum' => ['day', 'week', 'month']],
                'group_by'    => ['type' => ['string', 'null'], 'enum' => ['product', 'store', null]],
                'store_ids'   => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
            ],
        ];
    }

    public function getDefaultRender(): array
    {
        return ['primary' => 'line_chart'];
    }

    public function execute(array $args, array $scopeStoreIds): array
    {
        $conn   = Mage::getSingleton('core/resource')->getConnection('core_read');
        $r      = Mage::getSingleton('core/resource');
        $period = (new MageAustralia_AiReports_Model_PeriodNormalizer())->resolve($args['period']);

        $bucketExpr = match ($args['granularity']) {
            'day'   => "DATE(o.created_at)",
            'week'  => "DATE_SUB(DATE(o.created_at), INTERVAL WEEKDAY(o.created_at) DAY)",
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
                'date'         => new Zend_Db_Expr($bucketExpr),
                'series_label' => new Zend_Db_Expr("'Total'"),
                'value'        => new Zend_Db_Expr($valueExpr),
            ])
            ->where('o.created_at >= ?', $period['from'])
            ->where('o.created_at <= ?', $period['to'])
            ->where('o.state NOT IN (?)', ['canceled', 'closed'])
            ->group(new Zend_Db_Expr($bucketExpr))
            ->order('date ASC');

        if (!empty($scopeStoreIds)) {
            $select->where('o.store_id IN (?)', $scopeStoreIds);
        }

        return $this->shapeRows($conn->fetchAll($select));
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
