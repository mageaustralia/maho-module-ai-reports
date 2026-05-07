<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit;

use MageAustralia_AiReports_Helper_EmailRenderer as EmailRenderer;
use PHPUnit\Framework\TestCase;

final class EmailRendererTest extends TestCase
{
    private EmailRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new EmailRenderer();
    }

    // -------------------------------------------------------------------------
    // buildBodyHtml - general
    // -------------------------------------------------------------------------

    public function testReturnsEmptyStringForNoBlocks(): void
    {
        $html = $this->renderer->buildBodyHtml(['blocks' => []]);
        $this->assertSame('', $html);
    }

    public function testReturnsEmptyStringForMissingBlocksKey(): void
    {
        $html = $this->renderer->buildBodyHtml([]);
        $this->assertSame('', $html);
    }

    // -------------------------------------------------------------------------
    // KPI block
    // -------------------------------------------------------------------------

    public function testKpiBlockRendersLabelAndValue(): void
    {
        $envelope = [
            'blocks' => [
                ['type' => 'kpi', 'label' => 'Total Revenue', 'value' => 12345.67, 'format' => 'currency'],
            ],
        ];
        $html = $this->renderer->buildBodyHtml($envelope);
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('Total Revenue', $html);
        $this->assertStringContainsString('12,345.67', $html);
    }

    public function testKpiBlockEscapesHtml(): void
    {
        $envelope = [
            'blocks' => [
                ['type' => 'kpi', 'label' => '<script>alert(1)</script>', 'value' => 0, 'format' => 'integer'],
            ],
        ];
        $html = $this->renderer->buildBodyHtml($envelope);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // -------------------------------------------------------------------------
    // Table block
    // -------------------------------------------------------------------------

    public function testTableBlockRendersHeadersAndRows(): void
    {
        $envelope = [
            'blocks' => [
                [
                    'type'    => 'table',
                    'columns' => [
                        ['key' => 'label', 'label' => 'Product', 'format' => 'text'],
                        ['key' => 'value', 'label' => 'Revenue',  'format' => 'currency'],
                    ],
                    'rows' => [
                        ['cells' => ['label' => 'Widget A', 'value' => 999.5]],
                        ['cells' => ['label' => 'Widget B', 'value' => 0]],
                    ],
                ],
            ],
        ];
        $html = $this->renderer->buildBodyHtml($envelope);
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Product', $html);
        $this->assertStringContainsString('Revenue', $html);
        $this->assertStringContainsString('Widget A', $html);
        $this->assertStringContainsString('999.50', $html);
    }

    public function testTableBlockWithNoRowsShowsNoDataMessage(): void
    {
        $envelope = [
            'blocks' => [
                ['type' => 'table', 'columns' => [], 'rows' => []],
            ],
        ];
        $html = $this->renderer->buildBodyHtml($envelope);
        $this->assertStringContainsString('No data', $html);
    }

    // -------------------------------------------------------------------------
    // Chart block - skipped, not rendered server-side
    // -------------------------------------------------------------------------

    public function testChartBlockIsSkipped(): void
    {
        $envelope = [
            'blocks' => [
                ['type' => 'chart', 'chart_type' => 'bar', 'x_axis' => ['A'], 'series' => []],
            ],
        ];
        $html = $this->renderer->buildBodyHtml($envelope);
        $this->assertSame('', $html, 'Chart blocks should be silently skipped in email rendering');
    }

    // -------------------------------------------------------------------------
    // Format helpers
    // -------------------------------------------------------------------------

    public function testIntegerFormatRounds(): void
    {
        $envelope = [
            'blocks' => [
                ['type' => 'kpi', 'label' => 'Units', 'value' => 1234.9, 'format' => 'integer'],
            ],
        ];
        $html = $this->renderer->buildBodyHtml($envelope);
        $this->assertStringContainsString('1,235', $html);
    }

    public function testNumberFormatWithDecimals(): void
    {
        $envelope = [
            'blocks' => [
                ['type' => 'kpi', 'label' => 'Rate', 'value' => 3.14159, 'format' => 'number'],
            ],
        ];
        $html = $this->renderer->buildBodyHtml($envelope);
        $this->assertStringContainsString('3.14', $html);
    }

    public function testNullValueRendersHyphen(): void
    {
        $envelope = [
            'blocks' => [
                [
                    'type'    => 'table',
                    'columns' => [['key' => 'val', 'label' => 'V', 'format' => 'text']],
                    'rows'    => [['cells' => ['val' => null]]],
                ],
            ],
        ];
        $html = $this->renderer->buildBodyHtml($envelope);
        $this->assertStringContainsString('-', $html);
    }

    // -------------------------------------------------------------------------
    // Mixed blocks
    // -------------------------------------------------------------------------

    public function testMixedBlocksKpiAndTable(): void
    {
        $envelope = [
            'blocks' => [
                ['type' => 'kpi', 'label' => 'Count', 'value' => 42, 'format' => 'integer'],
                [
                    'type'    => 'table',
                    'columns' => [['key' => 'name', 'label' => 'Name', 'format' => 'text']],
                    'rows'    => [['cells' => ['name' => 'Foo']]],
                ],
            ],
        ];
        $html = $this->renderer->buildBodyHtml($envelope);
        $this->assertStringContainsString('Count', $html);
        $this->assertStringContainsString('42', $html);
        $this->assertStringContainsString('Foo', $html);
        $this->assertStringContainsString('<table', $html);
    }
}
