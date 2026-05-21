<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit;

use MageAustralia_AiReports_Model_PrimitiveInterface as PrimitiveInterface;
use MageAustralia_AiReports_Model_PrimitiveRegistry as PrimitiveRegistry;
use MageAustralia_AiReports_Model_PrimitiveExecutor as PrimitiveExecutor;
use MageAustralia_AiReports_Model_RenderEnvelopeBuilder as RenderEnvelopeBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Minimal stub of the helper used by PrimitiveExecutor for PII checks.
 * Cannot extend the real Helper_Data because Mage_Core_Helper_Abstract is not
 * available in the unit-test environment.
 */
final class FakeHelper
{
    public function __construct(private bool $piiAccess) {}
    public function canSeeCustomerPii(): bool { return $this->piiAccess; }
}

final class PrimitiveExecutorTest extends TestCase
{
    private function makeTopNPrimitive(array $rows): PrimitiveInterface
    {
        return new class($rows) implements PrimitiveInterface {
            public function __construct(private array $rows) {}
            public function getName(): string { return 'top_n'; }
            public function getDescription(): string { return ''; }
            public function getArgsSchema(): array { return ['type' => 'object']; }
            public function execute(array $a, array $s): array { return $this->rows; }
            public function getDefaultRender(): array { return ['primary' => 'table']; }
            public function supportsDrilldown(): bool { return false; }
            public function drill(array $a, array $s, array $k): ?array { return null; }
        };
    }

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
            public function supportsDrilldown(): bool { return false; }
            public function drill(array $a, array $s, array $k): ?array { return null; }
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

    public function testCustomerLabelsAreMaskedWhenHelperDeniesAccess(): void
    {
        $reg = new PrimitiveRegistry();
        $reg->register($this->makeTopNPrimitive([
            ['label' => 'customer@example.com', 'value' => 100, 'link_id' => 1],
            ['label' => 'other@example.com',    'value' => 50,  'link_id' => 2],
        ]));

        $exec = new PrimitiveExecutor($reg, new RenderEnvelopeBuilder(), new FakeHelper(false));
        $env = $exec->run(
            plan: [
                'primitive'   => 'top_n',
                'args'        => ['dimension' => 'customer'],
                'render_hint' => ['primary' => 'table'],
                'title'       => 'Customers',
                'narrative'   => '',
            ],
            effectiveStoreIds: [1],
            scopeWarning: false,
        );

        $tableBlock = $env['blocks'][0];
        $this->assertSame('table', $tableBlock['type']);
        foreach ($tableBlock['rows'] as $row) {
            $this->assertSame('[masked]', $row['cells']['label'],
                'Customer label must be masked when user lacks PII access');
        }
    }

    public function testCustomerLabelsAreVisibleWhenHelperGrantsAccess(): void
    {
        $reg = new PrimitiveRegistry();
        $reg->register($this->makeTopNPrimitive([
            ['label' => 'customer@example.com', 'value' => 100, 'link_id' => 1],
        ]));

        $exec = new PrimitiveExecutor($reg, new RenderEnvelopeBuilder(), new FakeHelper(true));
        $env = $exec->run(
            plan: [
                'primitive'   => 'top_n',
                'args'        => ['dimension' => 'customer'],
                'render_hint' => ['primary' => 'table'],
                'title'       => 'Customers',
                'narrative'   => '',
            ],
            effectiveStoreIds: [1],
            scopeWarning: false,
        );

        $tableBlock = $env['blocks'][0];
        $this->assertSame('table', $tableBlock['type']);
        $this->assertSame('customer@example.com', $tableBlock['rows'][0]['cells']['label']);
    }

    public function testNonCustomerDimensionLabelsAreNeverMasked(): void
    {
        $reg = new PrimitiveRegistry();
        $reg->register($this->makeTopNPrimitive([
            ['label' => 'Tennis Racket Pro', 'value' => 200, 'link_id' => 5],
        ]));

        // Helper denies PII - must not affect product labels.
        $exec = new PrimitiveExecutor($reg, new RenderEnvelopeBuilder(), new FakeHelper(false));
        $env = $exec->run(
            plan: [
                'primitive'   => 'top_n',
                'args'        => ['dimension' => 'product'],
                'render_hint' => ['primary' => 'table'],
                'title'       => 'Products',
                'narrative'   => '',
            ],
            effectiveStoreIds: [1],
            scopeWarning: false,
        );

        $tableBlock = $env['blocks'][0];
        $this->assertSame('Tennis Racket Pro', $tableBlock['rows'][0]['cells']['label']);
    }
}
