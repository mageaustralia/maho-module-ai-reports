<?php

declare(strict_types=1);

class MageAustralia_AiReports_Model_PeriodNormalizer
{
    public function __construct(private \DateTimeImmutable $today = new \DateTimeImmutable('today'))
    {
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
        $f = \DateTimeImmutable::createFromFormat('Y-m-d', $from);
        $t = \DateTimeImmutable::createFromFormat('Y-m-d', $to);
        if (!$f || !$t || $f > $t) {
            throw new \InvalidArgumentException('Invalid absolute period');
        }
        return [
            'from' => $f->format('Y-m-d') . ' 00:00:00',
            'to'   => $t->format('Y-m-d') . ' 23:59:59',
        ];
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
        return [
            'from' => $f->format('Y-m-d') . ' 00:00:00',
            'to'   => $t->format('Y-m-d') . ' 23:59:59',
        ];
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
}
