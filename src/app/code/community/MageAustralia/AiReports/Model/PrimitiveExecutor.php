<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_PrimitiveExecutor
{
    /**
     * @param object|null $helper  Any object with a canSeeCustomerPii(): bool method
     *                             (typically MageAustralia_AiReports_Helper_Data).
     *                             Typed as object so unit tests can pass lightweight stubs.
     */
    public function __construct(
        private MageAustralia_AiReports_Model_PrimitiveRegistry $registry,
        private MageAustralia_AiReports_Model_RenderEnvelopeBuilder $envelopeBuilder,
        private ?object $helper = null,
    ) {}

    /**
     * @param array<string, mixed> $plan
     * @param int[] $effectiveStoreIds
     * @return array<string, mixed>
     */
    public function run(array $plan, array $effectiveStoreIds, bool $scopeWarning): array
    {
        $primitive = $this->registry->get($plan['primitive']);
        $start = microtime(true);
        $rows  = $primitive->execute($plan['args'] ?? [], $effectiveStoreIds);
        $elapsedMs = (int) ((microtime(true) - $start) * 1000);

        $rows = $this->maybeApplyPiiMask($rows, $plan['args'] ?? []);

        $renderHint = $plan['render_hint'] ?? $primitive->getDefaultRender();
        $blocks     = $this->buildBlocks($rows, $renderHint, $primitive->getName(), $plan['args'] ?? []);

        return $this->envelopeBuilder->build(
            title: (string) ($plan['title'] ?? ''),
            narrative: (string) ($plan['narrative'] ?? ''),
            blocks: $blocks,
            scopeStoreIds: $effectiveStoreIds,
            scopeWarning: $scopeWarning,
            elapsedMs: $elapsedMs,
            executedAt: new \DateTimeImmutable('now'),
            rowCount: count($rows),
            supportsDrilldown: $primitive->supportsDrilldown(),
        );
    }

    /**
     * Masks the label field with "[masked]" when the primitive has a customer dimension
     * and the current admin user does not have customer PII access.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    private function maybeApplyPiiMask(array $rows, array $args): array
    {
        if (($args['dimension'] ?? null) !== 'customer') {
            return $rows;
        }
        if ($this->helper !== null && $this->helper->canSeeCustomerPii()) {
            return $rows;
        }
        return array_map(function (array $row): array {
            $row['label'] = '[masked]';
            return $row;
        }, $rows);
    }

    /**
     * Maps shaped result rows + render hint into a list of envelope blocks.
     * v1: emits one block of the primary type; secondary becomes a table when applicable.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildBlocks(array $rows, array $renderHint, string $primitiveName, array $args): array
    {
        $primary = $renderHint['primary'] ?? 'table';
        $blocks = [];

        switch ($primary) {
            case 'bar_chart':
            case 'line_chart':
            case 'pie_chart':
                $blocks[] = $this->chartBlock($rows, $primary, $primitiveName, $args);
                break;
            case 'kpi':
                $blocks[] = $this->kpiBlock($rows, $args);
                break;
            case 'table':
            default:
                // table-only handled below
                break;
        }

        // Secondary or default table.
        $blocks[] = $this->tableBlock($rows, $primitiveName);

        return $blocks;
    }

    private function chartBlock(array $rows, string $type, string $primitive, array $args): array
    {
        if ($primitive === 'time_series') {
            $byLabel = [];
            $allDates = [];
            foreach ($rows as $r) {
                $byLabel[$r['series_label']][$r['date']] = $r['value'];
                $allDates[$r['date']] = true;
            }
            $dates = array_keys($allDates);
            sort($dates);
            $series = [];
            foreach ($byLabel as $label => $byDate) {
                $data = array_map(fn ($d) => $byDate[$d] ?? 0.0, $dates);
                $series[] = ['name' => (string) $label, 'data' => $data];
            }
            return ['type' => 'chart', 'chart_type' => 'line', 'x_axis' => $dates, 'series' => $series];
        }

        $labels    = array_map(fn ($r) => (string) $r['label'], $rows);
        $chartType = $type === 'pie_chart' ? 'pie' : 'bar';

        if ($primitive === 'growth') {
            return [
                'type'       => 'chart',
                'chart_type' => 'bar',
                'x_axis'     => $labels,
                'series'     => [
                    ['name' => 'Period A', 'data' => array_map(fn ($r) => (float) ($r['value_a'] ?? 0), $rows)],
                    ['name' => 'Period B', 'data' => array_map(fn ($r) => (float) ($r['value_b'] ?? 0), $rows)],
                ],
            ];
        }

        if ($primitive === 'stock_vs_velocity' || $primitive === 'low_stock') {
            return [
                'type'       => 'chart',
                'chart_type' => 'bar',
                'x_axis'     => $labels,
                'series'     => [
                    ['name' => 'Days of cover', 'data' => array_map(fn ($r) => (float) ($r['days_of_cover'] ?? 0), $rows)],
                ],
            ];
        }

        // top_n, breakdown, default: rows have label + value
        $values = array_map(fn ($r) => (float) ($r['value'] ?? 0), $rows);
        return [
            'type'       => 'chart',
            'chart_type' => $chartType,
            'x_axis'     => $labels,
            'series'     => [['name' => $args['metric'] ?? 'value', 'data' => $values]],
        ];
    }

    private function kpiBlock(array $rows, array $args): array
    {
        $value = isset($rows[0]['value']) ? (float) $rows[0]['value'] : 0.0;
        return ['type' => 'kpi', 'label' => (string) ($args['metric'] ?? 'value'), 'value' => $value, 'format' => 'number'];
    }

    private function tableBlock(array $rows, string $primitive): array
    {
        if (empty($rows)) {
            return ['type' => 'table', 'columns' => [], 'rows' => []];
        }
        $columns = $this->columnsFor($primitive, $rows[0]);
        $tableRows = [];
        foreach ($rows as $r) {
            $cells = [];
            foreach ($columns as $col) {
                $cells[$col['key']] = $r[$col['key']] ?? null;
            }
            $entry = ['cells' => $cells];
            if (isset($r['link_url'])) $entry['link_url'] = $r['link_url'];
            if (isset($r['link_id'])) $entry['link_id'] = $r['link_id'];
            $tableRows[] = $entry;
        }
        return ['type' => 'table', 'columns' => $columns, 'rows' => $tableRows];
    }

    /** @param array<string, mixed> $sample @return array<int, array{key:string,label:string,format:string}> */
    private function columnsFor(string $primitive, array $sample): array
    {
        $columns = [];
        foreach ($sample as $key => $value) {
            if (in_array($key, ['link_id', 'link_url'], true)) continue;
            $columns[] = [
                'key'   => $key,
                'label' => ucwords(str_replace('_', ' ', $key)),
                'format' => is_int($value) || is_float($value) ? 'number' : 'text',
            ];
        }
        return $columns;
    }
}
