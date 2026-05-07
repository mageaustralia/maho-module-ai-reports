<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/**
 * Renders a report envelope as inline-styled HTML suitable for email delivery.
 *
 * Charts are not rendered server-side - every block falls back to its table
 * representation. KPI blocks render as a large-number box.
 */
class MageAustralia_AiReports_Helper_EmailRenderer extends Mage_Core_Helper_Abstract
{
    /**
     * Render the blocks section of an envelope into an HTML string.
     * The result is passed as the {{var body_html}} template variable.
     *
     * @param array<string, mixed> $envelope
     */
    public function buildBodyHtml(array $envelope): string
    {
        $html = '';

        foreach (($envelope['blocks'] ?? []) as $block) {
            $type = (string) ($block['type'] ?? '');
            switch ($type) {
                case 'kpi':
                    $html .= $this->renderKpi($block);
                    break;
                case 'table':
                    $html .= $this->renderTable($block);
                    break;
                case 'chart':
                    // Charts fall back to their table sibling; skip the chart block itself.
                    break;
                default:
                    break;
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderKpi(array $block): string
    {
        $label = htmlspecialchars((string) ($block['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $value = $this->formatValue($block['value'] ?? null, (string) ($block['format'] ?? ''));

        return '<div style="display:inline-block;background:#f4f4f5;border-radius:8px;padding:1rem 1.5rem;margin:0.75rem 0;">'
            . '<div style="font-size:0.8rem;color:#71717a;text-transform:uppercase;letter-spacing:0.05em;">' . $label . '</div>'
            . '<div style="font-size:2rem;font-weight:700;color:#18181b;">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</div>'
            . '</div>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderTable(array $block): string
    {
        $columns = $block['columns'] ?? [];
        $rows    = $block['rows'] ?? [];

        if (empty($columns) && empty($rows)) {
            return '<p style="color:#71717a;font-style:italic;">No data.</p>';
        }

        $html = '<table style="border-collapse:collapse;width:100%;font-size:0.875rem;margin:0.75rem 0;">';

        // Header
        $html .= '<thead><tr>';
        foreach ($columns as $col) {
            $label = htmlspecialchars((string) ($col['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $html .= '<th style="text-align:left;padding:0.5rem 0.75rem;border-bottom:2px solid #e4e4e7;white-space:nowrap;">'
                . $label . '</th>';
        }
        $html .= '</tr></thead>';

        // Body
        $html .= '<tbody>';
        $alt = false;
        foreach ($rows as $row) {
            $bg   = $alt ? 'background:#f9f9f9;' : '';
            $html .= '<tr style="' . $bg . '">';
            foreach ($columns as $col) {
                $key   = (string) ($col['key'] ?? '');
                $fmt   = (string) ($col['format'] ?? '');
                $raw   = $row['cells'][$key] ?? null;
                $value = $this->formatValue($raw, $fmt);
                $html .= '<td style="padding:0.5rem 0.75rem;border-bottom:1px solid #e4e4e7;">'
                    . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
            $alt = !$alt;
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * @param mixed $value
     */
    private function formatValue($value, string $format): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        switch ($format) {
            case 'integer':
                return number_format((int) round((float) $value));
            case 'currency':
                return '$' . number_format((float) $value, 2);
            case 'number':
                $n = (float) $value;
                if (floor($n) === $n) {
                    return number_format((int) $n);
                }
                return number_format($n, 2);
            default:
                return (string) $value;
        }
    }
}
