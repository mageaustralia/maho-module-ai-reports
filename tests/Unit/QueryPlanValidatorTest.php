<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit;

use MageAustralia_AiReports_Model_PrimitiveRegistry as PrimitiveRegistry;
use MageAustralia_AiReports_Model_QueryPlanValidator as Validator;
use MageAustralia_AiReports_Model_PrimitiveInterface as PrimitiveInterface;
use PHPUnit\Framework\TestCase;

final class QueryPlanValidatorTest extends TestCase
{
    public function testValidPlanReturnsEffectiveStoreIds(): void
    {
        $reg = new PrimitiveRegistry();
        $reg->register($this->topNStub());
        $v = new Validator($reg);

        $plan = [
            'primitive' => 'top_n',
            'args' => [
                'metric' => 'qty_sold',
                'dimension' => 'product',
                'period' => ['type' => 'relative', 'value' => 'last_complete_month'],
                'limit' => 20,
                'store_ids' => [1, 2],
            ],
            'render_hint' => ['primary' => 'bar_chart'],
            'title' => 'Top sellers',
            'narrative' => '',
        ];

        $result = $v->validate($plan, userAccessibleStoreIds: [1, 2, 3]);
        $this->assertSame([1, 2], $result['effectiveStoreIds']);
        $this->assertFalse($result['scopeWarning']);
    }

    public function testRequestedStoreOutsideAclSetsWarning(): void
    {
        $reg = new PrimitiveRegistry();
        $reg->register($this->topNStub());
        $v = new Validator($reg);

        $plan = $this->validPlan(storeIds: [1, 99]);
        $result = $v->validate($plan, userAccessibleStoreIds: [1, 2]);
        $this->assertSame([1], $result['effectiveStoreIds']);
        $this->assertTrue($result['scopeWarning']);
    }

    public function testNullStoreIdsDefaultsToUserAcl(): void
    {
        $reg = new PrimitiveRegistry();
        $reg->register($this->topNStub());
        $v = new Validator($reg);

        $plan = $this->validPlan(storeIds: null);
        $result = $v->validate($plan, userAccessibleStoreIds: [1, 2, 3]);
        $this->assertSame([1, 2, 3], $result['effectiveStoreIds']);
        $this->assertFalse($result['scopeWarning']);
    }

    public function testUnknownPrimitiveRejected(): void
    {
        $reg = new PrimitiveRegistry();
        $reg->register($this->topNStub());
        $v = new Validator($reg);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown primitive/i');
        $v->validate(['primitive' => 'nope', 'args' => []], userAccessibleStoreIds: [1]);
    }

    public function testArgsFailingSchemaRejected(): void
    {
        $reg = new PrimitiveRegistry();
        $reg->register($this->topNStub());
        $v = new Validator($reg);

        $plan = $this->validPlan();
        $plan['args']['limit'] = -5;

        $this->expectException(\InvalidArgumentException::class);
        $v->validate($plan, userAccessibleStoreIds: [1]);
    }

    /** @return array<string, mixed> */
    private function validPlan(array|null $storeIds = [1]): array
    {
        return [
            'primitive' => 'top_n',
            'args' => [
                'metric' => 'qty_sold',
                'dimension' => 'product',
                'period' => ['type' => 'relative', 'value' => 'last_complete_month'],
                'limit' => 20,
                'store_ids' => $storeIds,
            ],
            'render_hint' => ['primary' => 'bar_chart'],
            'title' => 'Top sellers',
            'narrative' => '',
        ];
    }

    private function topNStub(): PrimitiveInterface
    {
        return new class implements PrimitiveInterface {
            public function getName(): string { return 'top_n'; }
            public function getDescription(): string { return ''; }
            public function getArgsSchema(): array {
                return [
                    'type' => 'object',
                    'required' => ['metric', 'dimension', 'period', 'limit'],
                    'properties' => [
                        'metric' => ['type' => 'string', 'enum' => ['qty_sold', 'revenue']],
                        'dimension' => ['type' => 'string', 'enum' => ['product', 'sku']],
                        'period' => ['type' => 'object'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                        'store_ids' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
                    ],
                    'additionalProperties' => false,
                ];
            }
            public function execute(array $a, array $s): array { return []; }
            public function getDefaultRender(): array { return ['primary' => 'table']; }
            public function supportsDrilldown(): bool { return false; }
            public function drill(array $a, array $s, array $k): ?array { return null; }
        };
    }
}
