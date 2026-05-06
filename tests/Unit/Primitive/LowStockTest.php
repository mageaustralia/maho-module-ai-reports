<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit\Primitive;

use MageAustralia_AiReports_Model_Primitive_LowStock as LowStock;
use PHPUnit\Framework\TestCase;

final class LowStockTest extends TestCase
{
    public function testNameAndSchema(): void
    {
        $p = new LowStock();
        $this->assertSame('low_stock', $p->getName());
        $schema = $p->getArgsSchema();
        $this->assertContains('threshold_days', $schema['required']);
    }

    public function testShapeRowsFiltersAndSortsAscending(): void
    {
        $rows = (new LowStock())->shapeRows([
            ['sku' => 'X', 'label' => 'X', 'product_id' => '1', 'qty_on_hand' => '5',  'qty_sold' => '30', 'lookback_days' => 30],
            ['sku' => 'Y', 'label' => 'Y', 'product_id' => '2', 'qty_on_hand' => '60', 'qty_sold' => '30', 'lookback_days' => 30],
            ['sku' => 'Z', 'label' => 'Z', 'product_id' => '3', 'qty_on_hand' => '15', 'qty_sold' => '30', 'lookback_days' => 30],
        ], thresholdDays: 30);

        $this->assertCount(2, $rows);
        $this->assertSame('X', $rows[0]['sku']);
        $this->assertSame('Z', $rows[1]['sku']);
    }
}
