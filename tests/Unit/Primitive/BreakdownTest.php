<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit\Primitive;

use MageAustralia_AiReports_Model_Primitive_Breakdown as Breakdown;
use PHPUnit\Framework\TestCase;

final class BreakdownTest extends TestCase
{
    public function testNameAndDefaults(): void
    {
        $p = new Breakdown();
        $this->assertSame('breakdown', $p->getName());
        $this->assertSame(['primary' => 'pie_chart', 'secondary' => 'table'], $p->getDefaultRender());
    }

    public function testShapeRowsAddsSharePct(): void
    {
        $rows = (new Breakdown())->shapeRows([
            ['label' => 'A', 'link_id' => null, 'value' => '60'],
            ['label' => 'B', 'link_id' => null, 'value' => '30'],
            ['label' => 'C', 'link_id' => null, 'value' => '10'],
        ], dimension: 'category');

        $this->assertSame(60.0, $rows[0]['share_pct']);
        $this->assertSame(30.0, $rows[1]['share_pct']);
        $this->assertSame(10.0, $rows[2]['share_pct']);
    }

    public function testShapeRowsHandlesZeroTotal(): void
    {
        $rows = (new Breakdown())->shapeRows([
            ['label' => 'A', 'link_id' => null, 'value' => '0'],
        ], dimension: 'category');
        $this->assertSame(0.0, $rows[0]['share_pct']);
    }
}
