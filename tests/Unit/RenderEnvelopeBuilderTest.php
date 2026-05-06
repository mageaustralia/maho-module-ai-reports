<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit;

use MageAustralia_AiReports_Model_RenderEnvelopeBuilder as RenderEnvelopeBuilder;
use PHPUnit\Framework\TestCase;

final class RenderEnvelopeBuilderTest extends TestCase
{
    public function testBuildAssemblesEnvelope(): void
    {
        $builder = new RenderEnvelopeBuilder();
        $env = $builder->build(
            title: 'Top sellers',
            narrative: 'Last complete month.',
            blocks: [['type' => 'table', 'columns' => [], 'rows' => []]],
            scopeStoreIds: [1, 2],
            scopeWarning: false,
            elapsedMs: 187,
            executedAt: new \DateTimeImmutable('2026-05-06T03:14:00Z'),
            rowCount: 0,
        );

        $this->assertSame('Top sellers', $env['title']);
        $this->assertSame('Last complete month.', $env['narrative']);
        $this->assertCount(1, $env['blocks']);
        $this->assertSame([1, 2], $env['meta']['scope_store_ids']);
        $this->assertNull($env['meta']['scope_warning']);
        $this->assertSame(187, $env['meta']['elapsed_ms']);
        $this->assertSame(0, $env['meta']['row_count']);
        $this->assertSame('2026-05-06T03:14:00+00:00', $env['meta']['executed_at']);
    }

    public function testScopeWarningTextWhenSet(): void
    {
        $builder = new RenderEnvelopeBuilder();
        $env = $builder->build(
            title: 't', narrative: '', blocks: [],
            scopeStoreIds: [1], scopeWarning: true,
            elapsedMs: 1, executedAt: new \DateTimeImmutable('2026-05-06'), rowCount: 0,
        );
        $this->assertNotNull($env['meta']['scope_warning']);
        $this->assertStringContainsString('store', strtolower($env['meta']['scope_warning']));
    }
}
