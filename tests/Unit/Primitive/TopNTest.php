<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit\Primitive;

use MageAustralia_AiReports_Model_Primitive_TopN as TopN;
use PHPUnit\Framework\TestCase;

final class TopNTest extends TestCase
{
    public function testNameAndDescription(): void
    {
        $p = new TopN();
        $this->assertSame('top_n', $p->getName());
        $this->assertNotEmpty($p->getDescription());
    }

    public function testArgsSchemaShape(): void
    {
        $schema = (new TopN())->getArgsSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertContains('metric', $schema['required']);
        $this->assertContains('dimension', $schema['required']);
        $this->assertContains('period', $schema['required']);
        $this->assertContains('limit', $schema['required']);
        $this->assertSame(['qty_sold', 'revenue', 'net_revenue', 'order_count', 'aov', 'margin'],
            $schema['properties']['metric']['enum']);
        $this->assertSame(['product', 'sku', 'customer', 'store', 'order_status'],
            $schema['properties']['dimension']['enum']);
        $this->assertSame(1, $schema['properties']['limit']['minimum']);
        $this->assertSame(200, $schema['properties']['limit']['maximum']);
        $this->assertArrayHasKey('display_metrics', $schema['properties']);
        $this->assertSame(['array', 'null'], $schema['properties']['display_metrics']['type']);
        $this->assertSame(4, $schema['properties']['display_metrics']['maxItems']);
    }

    public function testDefaultRender(): void
    {
        $this->assertSame(
            ['primary' => 'bar_chart', 'secondary' => 'table'],
            (new TopN())->getDefaultRender()
        );
    }

    public function testShapeRowsBuildsLinkUrlForProductDimension(): void
    {
        $rows = (new TopN())->shapeRows(
            [['label' => 'Wilson Pro', 'value' => '234', 'link_id' => '4321']],
            dimension: 'product',
        );
        $this->assertSame('Wilson Pro', $rows[0]['label']);
        $this->assertSame(234, $rows[0]['value']);
        $this->assertSame(4321, $rows[0]['link_id']);
        $this->assertStringContainsString('catalog_product/edit/id/4321', $rows[0]['link_url']);
    }

    public function testShapeRowsOmitsLinkForNonEntityDimension(): void
    {
        $rows = (new TopN())->shapeRows(
            [['label' => 'pending', 'value' => '12', 'link_id' => null]],
            dimension: 'order_status',
        );
        $this->assertArrayNotHasKey('link_url', $rows[0]);
    }

    public function testShapeRowsEmitsExtraMetricColumns(): void
    {
        $rows = (new TopN())->shapeRows(
            [['label' => 'Wilson Pro', 'value' => '234', 'link_id' => '4321', 'revenue' => '12345.67', 'qty_sold' => '99']],
            dimension: 'product',
        );
        $this->assertSame(234, $rows[0]['value']);
        $this->assertSame(12345.67, $rows[0]['revenue']);
        $this->assertSame(99, $rows[0]['qty_sold']);
    }
}
