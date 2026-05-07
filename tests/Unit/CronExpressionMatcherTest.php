<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit;

use MageAustralia_AiReports_Model_CronExpressionMatcher as Matcher;
use PHPUnit\Framework\TestCase;

final class CronExpressionMatcherTest extends TestCase
{
    // -------------------------------------------------------------------------
    // isValid
    // -------------------------------------------------------------------------

    public function testValidExpressionsPass(): void
    {
        $exprs = [
            '* * * * *',
            '0 9 * * *',
            '0 9 1 * *',
            '0 9 * * 1',
            '*/15 * * * *',
            '0 0-6 * * *',
            '0 9 * * 1,2,3',
            '30 8,20 * * 1-5',
        ];
        foreach ($exprs as $expr) {
            $this->assertTrue(Matcher::isValid($expr), "Expected valid: $expr");
        }
    }

    public function testInvalidExpressionsReject(): void
    {
        $exprs = [
            '',
            '* * * *',          // only 4 fields
            '* * * * * *',      // 6 fields
            'not a cron',
            '? * * * *',        // ? not supported
        ];
        foreach ($exprs as $expr) {
            $this->assertFalse(Matcher::isValid($expr), "Expected invalid: $expr");
        }
    }

    // -------------------------------------------------------------------------
    // matches - wildcard
    // -------------------------------------------------------------------------

    public function testWildcardMatchesAnything(): void
    {
        $now = new \DateTimeImmutable('2026-03-15 14:27:00');
        $this->assertTrue(Matcher::matches('* * * * *', $now));
    }

    // -------------------------------------------------------------------------
    // matches - exact values
    // -------------------------------------------------------------------------

    public function testExactMinuteAndHourMatches(): void
    {
        $now = new \DateTimeImmutable('2026-03-15 09:00:00');
        $this->assertTrue(Matcher::matches('0 9 * * *', $now));
    }

    public function testExactMinuteAndHourDoesNotMatchWrongTime(): void
    {
        $now = new \DateTimeImmutable('2026-03-15 09:01:00');
        $this->assertFalse(Matcher::matches('0 9 * * *', $now));
    }

    // -------------------------------------------------------------------------
    // matches - day of month / month
    // -------------------------------------------------------------------------

    public function testFirstOfMonthAt9am(): void
    {
        $yes = new \DateTimeImmutable('2026-04-01 09:00:00');
        $no  = new \DateTimeImmutable('2026-04-02 09:00:00');
        $this->assertTrue(Matcher::matches('0 9 1 * *', $yes));
        $this->assertFalse(Matcher::matches('0 9 1 * *', $no));
    }

    // -------------------------------------------------------------------------
    // matches - day of week
    // -------------------------------------------------------------------------

    public function testMondayAt9am(): void
    {
        // 2026-03-16 is a Monday (dow = 1)
        $yes = new \DateTimeImmutable('2026-03-16 09:00:00');
        // 2026-03-17 is a Tuesday (dow = 2)
        $no  = new \DateTimeImmutable('2026-03-17 09:00:00');
        $this->assertTrue(Matcher::matches('0 9 * * 1', $yes));
        $this->assertFalse(Matcher::matches('0 9 * * 1', $no));
    }

    public function testDowSevenNormalisedToSundayZero(): void
    {
        // 2026-03-15 is a Sunday (dow = 0)
        $sun = new \DateTimeImmutable('2026-03-15 00:00:00');
        $this->assertTrue(Matcher::matches('0 0 * * 7', $sun));
        $this->assertTrue(Matcher::matches('0 0 * * 0', $sun));
    }

    // -------------------------------------------------------------------------
    // matches - step
    // -------------------------------------------------------------------------

    public function testEveryFifteenMinutes(): void
    {
        $yes = new \DateTimeImmutable('2026-01-01 10:15:00');
        $no  = new \DateTimeImmutable('2026-01-01 10:16:00');
        $this->assertTrue(Matcher::matches('*/15 * * * *', $yes));
        $this->assertFalse(Matcher::matches('*/15 * * * *', $no));
    }

    public function testStepOnHourRange(): void
    {
        // Every 2 hours from 0, so 0,2,4,...
        $yes = new \DateTimeImmutable('2026-01-01 04:00:00');
        $no  = new \DateTimeImmutable('2026-01-01 03:00:00');
        $this->assertTrue(Matcher::matches('0 0/2 * * *', $yes));
        $this->assertFalse(Matcher::matches('0 0/2 * * *', $no));
    }

    // -------------------------------------------------------------------------
    // matches - ranges and lists
    // -------------------------------------------------------------------------

    public function testRangeOfHours(): void
    {
        $yes = new \DateTimeImmutable('2026-01-01 06:00:00');
        $no  = new \DateTimeImmutable('2026-01-01 07:00:00');
        $this->assertTrue(Matcher::matches('0 0-6 * * *', $yes));
        $this->assertFalse(Matcher::matches('0 0-6 * * *', $no));
    }

    public function testListOfDows(): void
    {
        // Mon=1, Tue=2, Wed=3 => "1,2,3"
        $mon = new \DateTimeImmutable('2026-03-16 09:00:00');  // Monday
        $sat = new \DateTimeImmutable('2026-03-21 09:00:00');  // Saturday
        $this->assertTrue(Matcher::matches('0 9 * * 1,2,3', $mon));
        $this->assertFalse(Matcher::matches('0 9 * * 1,2,3', $sat));
    }

    // -------------------------------------------------------------------------
    // matches - monthly with specific month
    // -------------------------------------------------------------------------

    public function testSpecificMonthAndDay(): void
    {
        $yes = new \DateTimeImmutable('2026-01-01 00:00:00');
        $no  = new \DateTimeImmutable('2026-02-01 00:00:00');
        $this->assertTrue(Matcher::matches('0 0 1 1 *', $yes));
        $this->assertFalse(Matcher::matches('0 0 1 1 *', $no));
    }
}
