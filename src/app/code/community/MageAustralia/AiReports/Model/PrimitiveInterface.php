<?php

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
}
