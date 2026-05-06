<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit\Primitive;

use MageAustralia_AiReports_Model_Primitive_Growth as Growth;
use PHPUnit\Framework\TestCase;

final class GrowthTest extends TestCase
{
    public function testNameAndArgsSchema(): void
    {
        $p = new Growth();
        $this->assertSame('growth', $p->getName());
        $schema = $p->getArgsSchema();
        foreach (['metric', 'dimension', 'period_a', 'period_b', 'limit'] as $req) {
            $this->assertContains($req, $schema['required']);
        }
    }

    public function testShapeRowsComputesDeltas(): void
    {
        $rows = (new Growth())->shapeRows([
            ['label' => 'A', 'link_id' => '1', 'value_a' => '100', 'value_b' => '150'],
            ['label' => 'B', 'link_id' => '2', 'value_a' => '200', 'value_b' => '180'],
            ['label' => 'C', 'link_id' => '3', 'value_a' => '0',   'value_b' => '50'],
        ], dimension: 'product');

        $this->assertSame(50.0,  $rows[0]['delta_abs']);
        $this->assertSame(50.0,  $rows[0]['delta_pct']);
        $this->assertSame(-20.0, $rows[1]['delta_abs']);
        $this->assertSame(-10.0, $rows[1]['delta_pct']);
        $this->assertNull($rows[2]['delta_pct']);
    }

    public function testShapeRowsSortsByDeltaPctDesc(): void
    {
        $rows = (new Growth())->shapeRows([
            ['label' => 'A', 'link_id' => '1', 'value_a' => '100', 'value_b' => '110'],
            ['label' => 'B', 'link_id' => '2', 'value_a' => '100', 'value_b' => '200'],
        ], dimension: 'product');
        $this->assertSame('B', $rows[0]['label']);
        $this->assertSame('A', $rows[1]['label']);
    }
}
