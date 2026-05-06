<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit\Primitive;

use MageAustralia_AiReports_Model_Primitive_TimeSeries as TimeSeries;
use PHPUnit\Framework\TestCase;

final class TimeSeriesTest extends TestCase
{
    public function testNameAndDefaults(): void
    {
        $p = new TimeSeries();
        $this->assertSame('time_series', $p->getName());
        $this->assertSame(['primary' => 'line_chart'], $p->getDefaultRender());
    }

    public function testArgsSchema(): void
    {
        $schema = (new TimeSeries())->getArgsSchema();
        $this->assertContains('granularity', $schema['required']);
        $this->assertSame(['day', 'week', 'month'], $schema['properties']['granularity']['enum']);
    }

    public function testShapeRowsCastsTypes(): void
    {
        $rows = (new TimeSeries())->shapeRows([
            ['date' => '2026-04-01', 'series_label' => 'Total', 'value' => '12345.67'],
        ]);
        $this->assertSame('2026-04-01', $rows[0]['date']);
        $this->assertSame('Total', $rows[0]['series_label']);
        $this->assertSame(12345.67, $rows[0]['value']);
    }
}
