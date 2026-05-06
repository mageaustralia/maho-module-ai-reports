<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit\Primitive;

use MageAustralia_AiReports_Model_Primitive_StockVsVelocity as StockVsVelocity;
use PHPUnit\Framework\TestCase;

final class StockVsVelocityTest extends TestCase
{
    public function testNameAndArgsSchema(): void
    {
        $p = new StockVsVelocity();
        $this->assertSame('stock_vs_velocity', $p->getName());
        $schema = $p->getArgsSchema();
        $this->assertContains('product_filter', $schema['required']);
        $this->assertContains('lookback_days', $schema['required']);
        $this->assertSame(1, $schema['properties']['lookback_days']['minimum']);
        $this->assertSame(730, $schema['properties']['lookback_days']['maximum']);
    }

    public function testShapeRowsComputesDaysOfCover(): void
    {
        $rows = (new StockVsVelocity())->shapeRows([
            ['sku' => 'X', 'label' => 'Wilson', 'product_id' => '4321', 'qty_on_hand' => '100', 'qty_sold' => '60', 'lookback_days' => 30],
        ]);
        $this->assertSame(2.0, $rows[0]['daily_velocity']);
        $this->assertSame(50.0, $rows[0]['days_of_cover']);
    }

    public function testShapeRowsHandlesZeroVelocity(): void
    {
        $rows = (new StockVsVelocity())->shapeRows([
            ['sku' => 'X', 'label' => 'Wilson', 'product_id' => '4321', 'qty_on_hand' => '50', 'qty_sold' => '0', 'lookback_days' => 30],
        ]);
        $this->assertSame(0.0, $rows[0]['daily_velocity']);
        $this->assertNull($rows[0]['days_of_cover']);
    }
}
