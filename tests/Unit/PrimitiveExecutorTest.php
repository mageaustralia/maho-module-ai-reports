<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit;

use MageAustralia_AiReports_Model_PrimitiveInterface as PrimitiveInterface;
use MageAustralia_AiReports_Model_PrimitiveRegistry as PrimitiveRegistry;
use MageAustralia_AiReports_Model_PrimitiveExecutor as PrimitiveExecutor;
use MageAustralia_AiReports_Model_RenderEnvelopeBuilder as RenderEnvelopeBuilder;
use PHPUnit\Framework\TestCase;

final class PrimitiveExecutorTest extends TestCase
{
    public function testExecuteWrapsResultInEnvelope(): void
    {
        $reg = new PrimitiveRegistry();
        $reg->register(new class implements PrimitiveInterface {
            public function getName(): string { return 'fake'; }
            public function getDescription(): string { return ''; }
            public function getArgsSchema(): array { return ['type' => 'object']; }
            public function execute(array $a, array $s): array {
                return [['label' => 'A', 'value' => 1]];
            }
            public function getDefaultRender(): array { return ['primary' => 'table']; }
        });

        $exec = new PrimitiveExecutor($reg, new RenderEnvelopeBuilder());
        $env = $exec->run(
            plan: ['primitive' => 'fake', 'args' => [], 'render_hint' => ['primary' => 'table'], 'title' => 'T', 'narrative' => 'N'],
            effectiveStoreIds: [1, 2],
            scopeWarning: false,
        );

        $this->assertSame('T', $env['title']);
        $this->assertCount(1, $env['blocks']);
        $this->assertSame('table', $env['blocks'][0]['type'] ?? null);
        $this->assertSame(1, $env['meta']['row_count']);
    }
}
