<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_PeriodNormalizer
{
    public function __construct(
        private \DateTimeImmutable $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC')),
        private string $storeTimezone = 'UTC',
    ) {
        // Ensure $today carries the store timezone for boundary math.
        if ($this->today->getTimezone()->getName() !== $this->storeTimezone) {
            $this->today = $this->today->setTimezone(new \DateTimeZone($this->storeTimezone));
        }
    }

    /**
     * @param array{type: string, value?: string, from?: string, to?: string} $period
     * @return array{from: string, to: string}
     */
    public function resolve(array $period): array
    {
        if (($period['type'] ?? null) === 'absolute') {
            return $this->resolveAbsolute($period['from'] ?? '', $period['to'] ?? '');
        }
        if (($period['type'] ?? null) === 'relative') {
            return $this->resolveRelative($period['value'] ?? '');
        }
        throw new \InvalidArgumentException('Period type must be "relative" or "absolute"');
    }

    private function resolveAbsolute(string $from, string $to): array
    {
        $tz = new \DateTimeZone($this->storeTimezone);
        $f  = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $t  = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
        if (!$f || !$t || $f > $t) {
            throw new \InvalidArgumentException('Invalid absolute period');
        }
        return $this->toUtcRange($f, $t);
    }

    private function resolveRelative(string $key): array
    {
        $today = $this->today;
        switch ($key) {
            case 'today':
                $f = $today; $t = $today; break;
            case 'yesterday':
                $f = $today->modify('-1 day'); $t = $f; break;
            case 'this_week':
                $f = $today->modify('monday this week'); $t = $today; break;
            case 'last_complete_week':
                $f = $today->modify('monday last week'); $t = $f->modify('+6 days'); break;
            case 'this_month':
                $f = $today->modify('first day of this month'); $t = $today; break;
            case 'last_complete_month':
                $f = $today->modify('first day of last month');
                $t = $today->modify('last day of last month');
                break;
            case 'this_quarter':
                $f = $this->quarterStart($today); $t = $today; break;
            case 'last_complete_quarter':
                $start = $this->quarterStart($today)->modify('-3 months');
                $f = $start; $t = $start->modify('+3 months -1 day'); break;
            case 'this_year':
                $f = $today->modify('first day of January this year'); $t = $today; break;
            case 'last_complete_year':
                $f = $today->modify('first day of January last year');
                $t = $today->modify('last day of December last year');
                break;
            case 'last_7_days':
                $f = $today->modify('-7 days'); $t = $today; break;
            case 'last_30_days':
                $f = $today->modify('-30 days'); $t = $today; break;
            case 'last_90_days':
                $f = $today->modify('-90 days'); $t = $today; break;
            case 'last_180_days':
                $f = $today->modify('-180 days'); $t = $today; break;
            case 'last_365_days':
                $f = $today->modify('-365 days'); $t = $today; break;
            default:
                throw new \InvalidArgumentException("Unknown relative period: $key");
        }
        return $this->toUtcRange($f, $t);
    }

    /**
     * Convert two DateTimeImmutables (assumed to be in store TZ) into a UTC
     * 'from'/'to' string pair representing the inclusive day boundaries.
     *
     * @return array{from: string, to: string}
     */
    private function toUtcRange(\DateTimeImmutable $f, \DateTimeImmutable $t): array
    {
        $utc  = new \DateTimeZone('UTC');
        $from = $f->setTime(0, 0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
        $to   = $t->setTime(23, 59, 59)->setTimezone($utc)->format('Y-m-d H:i:s');
        return ['from' => $from, 'to' => $to];
    }

    private function quarterStart(\DateTimeImmutable $d): \DateTimeImmutable
    {
        $q = (int) ceil(((int) $d->format('n')) / 3);
        $startMonth = ($q - 1) * 3 + 1;
        return $d->setDate((int) $d->format('Y'), $startMonth, 1);
    }

    /** @return string[] */
    public static function relativeKeys(): array
    {
        return [
            'today', 'yesterday',
            'this_week', 'last_complete_week',
            'this_month', 'last_complete_month',
            'this_quarter', 'last_complete_quarter',
            'this_year', 'last_complete_year',
            'last_7_days', 'last_30_days', 'last_90_days', 'last_180_days', 'last_365_days',
        ];
    }

    /**
     * JSON Schema (draft-07) describing a valid period struct.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type'  => 'object',
            'oneOf' => [
                [
                    'required'   => ['type', 'value'],
                    'properties' => [
                        'type'  => ['const' => 'relative'],
                        'value' => ['type' => 'string', 'enum' => self::relativeKeys()],
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'required'   => ['type', 'from', 'to'],
                    'properties' => [
                        'type' => ['const' => 'absolute'],
                        'from' => ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
                        'to'   => ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
