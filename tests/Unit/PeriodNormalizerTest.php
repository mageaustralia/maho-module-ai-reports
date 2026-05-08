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
        // Default UTC - behaviour unchanged from pre-refactor.
        $this->normalizer = new PeriodNormalizer(
            new \DateTimeImmutable('2026-05-06', new \DateTimeZone('UTC')),
            'UTC',
        );
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

    // --- Store-timezone tests (Australia/Sydney = AEST UTC+10 / AEDT UTC+11) ---

    /**
     * today=2026-05-08 in AEST (UTC+10).
     * last_30_days: from=2026-04-08 00:00 AEST = 2026-04-07 14:00:00 UTC
     *                 to=2026-05-08 23:59:59 AEST = 2026-05-08 13:59:59 UTC
     * Note: AEST is UTC+10 (no DST in May).
     */
    public function testRelativeLast30DaysSydneyTimezone(): void
    {
        $norm = new PeriodNormalizer(
            new \DateTimeImmutable('2026-05-08', new \DateTimeZone('Australia/Sydney')),
            'Australia/Sydney',
        );
        $r = $norm->resolve(['type' => 'relative', 'value' => 'last_30_days']);
        $this->assertSame('2026-04-07 14:00:00', $r['from']);
        $this->assertSame('2026-05-08 13:59:59', $r['to']);
    }

    /**
     * today=2026-05-08 in AEST.
     * yesterday: from/to = 2026-05-07 00:00 AEST .. 2026-05-07 23:59:59 AEST
     *          = 2026-05-06 14:00:00 UTC .. 2026-05-07 13:59:59 UTC
     */
    public function testRelativeYesterdaySydneyTimezone(): void
    {
        $norm = new PeriodNormalizer(
            new \DateTimeImmutable('2026-05-08', new \DateTimeZone('Australia/Sydney')),
            'Australia/Sydney',
        );
        $r = $norm->resolve(['type' => 'relative', 'value' => 'yesterday']);
        $this->assertSame('2026-05-06 14:00:00', $r['from']);
        $this->assertSame('2026-05-07 13:59:59', $r['to']);
    }

    /**
     * Absolute period in Sydney timezone spanning a DST transition.
     * 2026-04-01 is still AEDT (UTC+11), so 00:00 AEDT = 2026-03-31 13:00:00 UTC.
     * 2026-04-30 is AEST (UTC+10), so 23:59:59 AEST = 2026-04-30 13:59:59 UTC.
     */
    public function testAbsolutePeriodSydneyTimezone(): void
    {
        $norm = new PeriodNormalizer(
            new \DateTimeImmutable('2026-05-08', new \DateTimeZone('Australia/Sydney')),
            'Australia/Sydney',
        );
        $r = $norm->resolve(['type' => 'absolute', 'from' => '2026-04-01', 'to' => '2026-04-30']);
        $this->assertSame('2026-03-31 13:00:00', $r['from']);
        $this->assertSame('2026-04-30 13:59:59', $r['to']);
    }
}
