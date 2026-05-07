<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/**
 * Minimal 5-field cron expression matcher.
 *
 * Supports: wildcard (*), exact value (5), list (1,2,3), range (1-5),
 * and step (star-slash-N or range-slash-N, e.g. 0/15 or 0-59/5).
 *
 * Fields (left to right): minute, hour, day-of-month, month, day-of-week.
 * Day-of-week: 0 = Sunday, 6 = Saturday (0 and 7 both accepted for Sunday).
 */
class MageAustralia_AiReports_Model_CronExpressionMatcher
{
    /**
     * Validate that a string looks like a 5-field cron expression.
     * Does not verify value ranges - just structural validity.
     */
    public static function isValid(string $expr): bool
    {
        $expr = trim($expr);
        $parts = preg_split('/\s+/', $expr);
        if (!is_array($parts) || count($parts) !== 5) {
            return false;
        }
        $fieldPattern = '/^(\*|\d+)([,\-\/]\S*)*$/';
        foreach ($parts as $part) {
            if (!preg_match($fieldPattern, $part)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns true if $now matches the given 5-field cron expression.
     */
    public static function matches(string $expr, \DateTimeImmutable $now): bool
    {
        $parts = preg_split('/\s+/', trim($expr));
        if (!is_array($parts) || count($parts) !== 5) {
            return false;
        }

        [$minExpr, $hourExpr, $domExpr, $monExpr, $dowExpr] = $parts;

        $minute  = (int) $now->format('i');
        $hour    = (int) $now->format('G');
        $dom     = (int) $now->format('j');
        $month   = (int) $now->format('n');
        $dow     = (int) $now->format('w');  // 0 = Sunday

        return self::fieldMatches($minExpr,  $minute,  0, 59)
            && self::fieldMatches($hourExpr, $hour,    0, 23)
            && self::fieldMatches($domExpr,  $dom,     1, 31)
            && self::fieldMatches($monExpr,  $month,   1, 12)
            && self::fieldMatchesDow($dowExpr, $dow);
    }

    /**
     * Match a single cron field against a value.
     */
    private static function fieldMatches(string $field, int $value, int $min, int $max): bool
    {
        // Comma-separated list: try each segment
        if (str_contains($field, ',')) {
            foreach (explode(',', $field) as $segment) {
                if (self::segmentMatches($segment, $value, $min, $max)) {
                    return true;
                }
            }
            return false;
        }

        return self::segmentMatches($field, $value, $min, $max);
    }

    /**
     * Day-of-week matching normalises 7 -> 0 (both mean Sunday).
     */
    private static function fieldMatchesDow(string $field, int $dow): bool
    {
        if ($field === '*') {
            return true;
        }

        // Normalise field: replace 7 with 0
        $field = str_replace('7', '0', $field);

        return self::fieldMatches($field, $dow, 0, 6);
    }

    /**
     * Match a single segment (may include step or range, no comma).
     */
    private static function segmentMatches(string $segment, int $value, int $min, int $max): bool
    {
        // Step: */N or start/N or start-end/N
        if (str_contains($segment, '/')) {
            [$base, $stepStr] = explode('/', $segment, 2);
            $step = (int) $stepStr;
            if ($step <= 0) return false;

            if ($base === '*') {
                $rangeMin = $min;
                $rangeMax = $max;
            } elseif (str_contains($base, '-')) {
                [$rangeMin, $rangeMax] = array_map('intval', explode('-', $base, 2));
            } else {
                $rangeMin = (int) $base;
                $rangeMax = $max;
            }

            if ($value < $rangeMin || $value > $rangeMax) return false;
            return ($value - $rangeMin) % $step === 0;
        }

        // Range: start-end
        if (str_contains($segment, '-')) {
            [$lo, $hi] = array_map('intval', explode('-', $segment, 2));
            return $value >= $lo && $value <= $hi;
        }

        // Wildcard
        if ($segment === '*') {
            return true;
        }

        // Exact value
        return (int) $segment === $value;
    }
}
