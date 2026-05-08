<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/**
 * Pure-logic helper that applies a per-run date-period override to a query plan.
 *
 * Rules:
 *  - If the plan args has a `period` key, it is replaced with the absolute override.
 *  - If the plan args has a `period_a` key (growth primitive), period_a is replaced.
 *  - Otherwise the plan is returned unchanged (e.g. stock_vs_velocity / low_stock).
 *
 * The override is NOT persisted - it is applied only at run time.
 */
class MageAustralia_AiReports_Model_PeriodOverrider
{
    /**
     * Apply a date-period override to the given plan.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function applyOverride(array $plan, ?string $from, ?string $to): array
    {
        if (!self::isValidIsoDate($from) || !self::isValidIsoDate($to)) {
            return $plan;
        }
        $override = ['type' => 'absolute', 'from' => $from, 'to' => $to];
        $args = $plan['args'] ?? [];
        if (isset($args['period'])) {
            $args['period'] = $override;
        } elseif (isset($args['period_a'])) {
            $args['period_a'] = $override;
        } else {
            return $plan;
        }
        $plan['args'] = $args;
        return $plan;
    }

    /**
     * Return true only when $s is a well-formed, calendar-valid YYYY-MM-DD string.
     */
    public static function isValidIsoDate(?string $s): bool
    {
        if (!$s) {
            return false;
        }
        if (!(bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        return checkdate(
            (int) substr($s, 5, 2),
            (int) substr($s, 8, 2),
            (int) substr($s, 0, 4),
        );
    }
}
