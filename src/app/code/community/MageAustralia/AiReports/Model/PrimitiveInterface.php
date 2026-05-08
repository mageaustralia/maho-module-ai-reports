<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

interface MageAustralia_AiReports_Model_PrimitiveInterface
{
    public function getName(): string;

    /** Human-readable description shown to the LLM in the system prompt. */
    public function getDescription(): string;

    /**
     * JSON Schema (draft-07) for the args object accepted by execute().
     * @return array<string, mixed>
     */
    public function getArgsSchema(): array;

    /**
     * @param array<string, mixed> $args  validated args matching getArgsSchema()
     * @param int[]                $scopeStoreIds  effective store_ids after ACL intersection
     * @return array<int, array<string, mixed>>  shaped result rows
     */
    public function execute(array $args, array $scopeStoreIds): array;

    /**
     * Default render hint when the LLM doesn't supply one.
     * @return array{primary: string, secondary?: string}
     */
    public function getDefaultRender(): array;

    /**
     * Return the contributing records for a specific result row, or null if drilldown
     * is not supported for this primitive.
     *
     * @param array<string, mixed> $args          The original query plan args
     * @param int[]                $scopeStoreIds Effective store IDs (already ACL-intersected)
     * @param array<string, mixed> $rowKey        The row to drill into - typically {link_id, label}
     * @return array<int, array<string, mixed>>|null  Sub-rows or null if not supported
     */
    public function drill(array $args, array $scopeStoreIds, array $rowKey): ?array;
}
