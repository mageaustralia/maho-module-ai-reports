<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit;

use MageAustralia_AiReports_Model_PeriodOverrider as PeriodOverrider;
use PHPUnit\Framework\TestCase;

final class PeriodOverriderTest extends TestCase
{
    // --- applyOverride ---

    public function testOverridePlanWithPeriodKey(): void
    {
        $plan = [
            'primitive' => 'top_n',
            'args'      => ['period' => ['type' => 'relative', 'value' => 'last_30_days'], 'limit' => 10],
        ];
        $result = PeriodOverrider::applyOverride($plan, '2026-03-01', '2026-03-31');
        $this->assertSame(
            ['type' => 'absolute', 'from' => '2026-03-01', 'to' => '2026-03-31'],
            $result['args']['period'],
        );
    }

    public function testOverridePlanWithPeriodAKey(): void
    {
        $plan = [
            'primitive' => 'growth',
            'args'      => [
                'period_a' => ['type' => 'relative', 'value' => 'this_month'],
                'period_b' => ['type' => 'relative', 'value' => 'last_month'],
            ],
        ];
        $result = PeriodOverrider::applyOverride($plan, '2026-03-01', '2026-03-31');
        $this->assertSame(
            ['type' => 'absolute', 'from' => '2026-03-01', 'to' => '2026-03-31'],
            $result['args']['period_a'],
        );
        // period_b must remain unchanged.
        $this->assertSame(['type' => 'relative', 'value' => 'last_month'], $result['args']['period_b']);
    }

    public function testOverrideIgnoredWhenNeitherPeriodKeyPresent(): void
    {
        $plan = [
            'primitive' => 'stock_vs_velocity',
            'args'      => ['lookback_days' => 90],
        ];
        $result = PeriodOverrider::applyOverride($plan, '2026-03-01', '2026-03-31');
        $this->assertSame($plan, $result);
    }

    public function testOverrideIgnoredWhenFromIsEmpty(): void
    {
        $plan = [
            'primitive' => 'top_n',
            'args'      => ['period' => ['type' => 'relative', 'value' => 'last_30_days']],
        ];
        $result = PeriodOverrider::applyOverride($plan, '', '2026-03-31');
        $this->assertSame($plan, $result);
    }

    public function testOverrideIgnoredWhenFromIsNull(): void
    {
        $plan = [
            'primitive' => 'top_n',
            'args'      => ['period' => ['type' => 'relative', 'value' => 'last_30_days']],
        ];
        $result = PeriodOverrider::applyOverride($plan, null, '2026-03-31');
        $this->assertSame($plan, $result);
    }

    public function testOverrideIgnoredWhenInvalidDateString(): void
    {
        $plan = [
            'primitive' => 'top_n',
            'args'      => ['period' => ['type' => 'relative', 'value' => 'last_30_days']],
        ];
        $result = PeriodOverrider::applyOverride($plan, 'not-a-date', '2026-03-31');
        $this->assertSame($plan, $result);
    }

    public function testOverridePassesThroughWhenFromAfterTo(): void
    {
        // The validator downstream will catch the inverted range; PeriodOverrider
        // does not enforce ordering - it just substitutes the dates verbatim.
        $plan = [
            'primitive' => 'top_n',
            'args'      => ['period' => ['type' => 'relative', 'value' => 'last_30_days']],
        ];
        $result = PeriodOverrider::applyOverride($plan, '2026-03-31', '2026-03-01');
        $this->assertSame(
            ['type' => 'absolute', 'from' => '2026-03-31', 'to' => '2026-03-01'],
            $result['args']['period'],
        );
    }

    // --- isValidIsoDate ---

    public function testValidIsoDate(): void
    {
        $this->assertTrue(PeriodOverrider::isValidIsoDate('2026-01-01'));
        $this->assertTrue(PeriodOverrider::isValidIsoDate('2026-12-31'));
        $this->assertTrue(PeriodOverrider::isValidIsoDate('2024-02-29')); // leap year
    }

    public function testInvalidIsoDateNull(): void
    {
        $this->assertFalse(PeriodOverrider::isValidIsoDate(null));
    }

    public function testInvalidIsoDateEmpty(): void
    {
        $this->assertFalse(PeriodOverrider::isValidIsoDate(''));
    }

    public function testInvalidIsoDateWrongFormat(): void
    {
        $this->assertFalse(PeriodOverrider::isValidIsoDate('01-03-2026'));
        $this->assertFalse(PeriodOverrider::isValidIsoDate('2026/03/01'));
        $this->assertFalse(PeriodOverrider::isValidIsoDate('20260301'));
    }

    public function testInvalidIsoDateNonExistentDay(): void
    {
        $this->assertFalse(PeriodOverrider::isValidIsoDate('2026-02-29')); // not a leap year
        $this->assertFalse(PeriodOverrider::isValidIsoDate('2026-04-31')); // April has 30 days
    }
}
