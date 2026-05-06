<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_RenderEnvelopeBuilder
{
    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param int[]                            $scopeStoreIds
     * @return array<string, mixed>
     */
    public function build(
        string $title,
        string $narrative,
        array $blocks,
        array $scopeStoreIds,
        bool $scopeWarning,
        int $elapsedMs,
        \DateTimeImmutable $executedAt,
        int $rowCount,
    ): array {
        return [
            'title'     => $title,
            'narrative' => $narrative,
            'blocks'    => array_values($blocks),
            'meta'      => [
                'executed_at'     => $executedAt->format('c'),
                'elapsed_ms'      => $elapsedMs,
                'row_count'       => $rowCount,
                'scope_store_ids' => array_values($scopeStoreIds),
                'scope_warning'   => $scopeWarning
                    ? 'Some requested stores were filtered out by your access scope.'
                    : null,
            ],
        ];
    }
}
