<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit;

use MageAustralia_AiReports_Model_PeriodNormalizer as PeriodNormalizer;
use PHPUnit\Framework\TestCase;

final class PeriodNormalizerTest extends TestCase
{
    private PeriodNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new PeriodNormalizer(new \DateTimeImmutable('2026-05-06'));
    }

    public function testRelativeLastCompleteMonth(): void
    {
        $r = $this->normalizer->resolve(['type' => 'relative', 'value' => 'last_complete_month']);
        $this->assertSame(['from' => '2026-04-01 00:00:00', 'to' => '2026-04-30 23:59:59'], $r);
    }

    public function testRelativeLast30Days(): void
    {
        $r = $this->normalizer->resolve(['type' => 'relative', 'value' => 'last_30_days']);
        $this->assertSame(['from' => '2026-04-06 00:00:00', 'to' => '2026-05-06 23:59:59'], $r);
    }

    public function testAbsolutePassesThroughWithBoundaryNormalisation(): void
    {
        $r = $this->normalizer->resolve(['type' => 'absolute', 'from' => '2026-04-01', 'to' => '2026-04-30']);
        $this->assertSame(['from' => '2026-04-01 00:00:00', 'to' => '2026-04-30 23:59:59'], $r);
    }

    public function testRejectsUnknownRelativeKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalizer->resolve(['type' => 'relative', 'value' => 'not_a_real_key']);
    }

    public function testRejectsAbsoluteFromAfterTo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalizer->resolve(['type' => 'absolute', 'from' => '2026-05-01', 'to' => '2026-04-01']);
    }
}
