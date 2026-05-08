<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit\Primitive;

use MageAustralia_AiReports_Model_Primitive_Breakdown as Breakdown;
use MageAustralia_AiReports_Model_Primitive_Growth as Growth;
use MageAustralia_AiReports_Model_Primitive_LowStock as LowStock;
use MageAustralia_AiReports_Model_Primitive_StockVsVelocity as StockVsVelocity;
use MageAustralia_AiReports_Model_Primitive_TimeSeries as TimeSeries;
use MageAustralia_AiReports_Model_Primitive_TopN as TopN;
use PHPUnit\Framework\TestCase;

/**
 * Interface compliance and stub-return tests for drill().
 * SQL execution is staging-verified; these tests cover the
 * method signature, guard conditions, and non-drillable stubs.
 */
final class DrillTest extends TestCase
{
    // --- Interface compliance ---

    public function testAllPrimitivesImplementDrillMethod(): void
    {
        $primitives = [
            new TopN(),
            new Growth(),
            new Breakdown(),
            new TimeSeries(),
            new StockVsVelocity(),
            new LowStock(),
        ];
        foreach ($primitives as $p) {
            $this->assertTrue(
                method_exists($p, 'drill'),
                get_class($p) . ' must implement drill()',
            );
        }
    }

    // --- Non-drillable primitives return null ---

    public function testTimeSeriesDrillReturnsNull(): void
    {
        $this->assertNull((new TimeSeries())->drill([], [], []));
    }

    public function testStockVsVelocityDrillReturnsNull(): void
    {
        $this->assertNull((new StockVsVelocity())->drill([], [], []));
    }

    public function testLowStockDrillReturnsNull(): void
    {
        $this->assertNull((new LowStock())->drill([], [], []));
    }

    // --- TopN guards ---

    public function testTopNDrillReturnsNullForOrderStatusDimension(): void
    {
        $args = [
            'dimension' => 'order_status',
            'period'    => ['preset' => 'last_30_days'],
            'metric'    => 'revenue',
            'limit'     => 10,
        ];
        $this->assertNull((new TopN())->drill($args, [], ['link_id' => null, 'label' => 'pending']));
    }

    public function testTopNDrillReturnsNullWhenLinkIdNullAndNotCustomer(): void
    {
        // product dimension with null link_id - should return null (no link to join on).
        $args = [
            'dimension' => 'product',
            'period'    => ['preset' => 'last_30_days'],
            'metric'    => 'revenue',
            'limit'     => 10,
        ];
        $this->assertNull((new TopN())->drill($args, [], ['link_id' => null, 'label' => 'Some Product']));
    }

    // --- Growth guards ---

    public function testGrowthDrillReturnsNullForOrderStatusDimension(): void
    {
        $args = [
            'dimension' => 'order_status',
            'period_a'  => ['preset' => 'last_30_days'],
            'period_b'  => ['preset' => 'previous_30_days'],
            'metric'    => 'revenue',
            'limit'     => 10,
        ];
        $this->assertNull((new Growth())->drill($args, [], ['link_id' => null, 'label' => 'pending']));
    }

    public function testGrowthDrillReturnsNullWhenLinkIdNullAndNotCustomer(): void
    {
        $args = [
            'dimension' => 'product',
            'period_a'  => ['preset' => 'last_30_days'],
            'period_b'  => ['preset' => 'previous_30_days'],
            'metric'    => 'revenue',
            'limit'     => 10,
        ];
        $this->assertNull((new Growth())->drill($args, [], ['link_id' => null, 'label' => 'Widget']));
    }

    // --- Breakdown guards ---

    public function testBreakdownDrillReturnsNullForOrderStatusDimension(): void
    {
        $args = [
            'dimension' => 'order_status',
            'period'    => ['preset' => 'last_30_days'],
            'metric'    => 'revenue',
        ];
        $this->assertNull((new Breakdown())->drill($args, [], ['link_id' => null, 'label' => 'pending']));
    }

    public function testBreakdownDrillReturnsNullWhenLinkIdNullAndNotCustomer(): void
    {
        $args = [
            'dimension' => 'product',
            'period'    => ['preset' => 'last_30_days'],
            'metric'    => 'revenue',
        ];
        $this->assertNull((new Breakdown())->drill($args, [], ['link_id' => null, 'label' => 'Widget']));
    }
}
