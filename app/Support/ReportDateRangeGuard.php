<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Keeps report date filters inside a hot window so MySQL does not scan unbounded history.
 */
class ReportDateRangeGuard
{
    public const MAX_RANGE_DAYS = 90;

    public const DEFAULT_RANGE_DAYS = 30;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function enforce(array $filters, ?int $defaultDays = null, ?int $maxDays = null): array
    {
        $maxDays = max(1, min(365, $maxDays ?? (int) config('data_retention.report_max_range_days', self::MAX_RANGE_DAYS)));
        $defaultDays = max(1, min($maxDays, $defaultDays ?? (int) config('data_retention.report_default_range_days', self::DEFAULT_RANGE_DAYS)));

        $from = self::normalizeDate($filters['from_date'] ?? null);
        $to = self::normalizeDate($filters['to_date'] ?? null);

        if ($from === null && $to === null) {
            $toCarbon = Carbon::today();
            $fromCarbon = $toCarbon->copy()->subDays($defaultDays - 1);
            $filters['from_date'] = $fromCarbon->toDateString();
            $filters['to_date'] = $toCarbon->toDateString();
            $filters['date_range_applied_default'] = true;

            return $filters;
        }

        $toCarbon = $to ? Carbon::parse($to)->startOfDay() : Carbon::today();
        $fromCarbon = $from ? Carbon::parse($from)->startOfDay() : $toCarbon->copy()->subDays($defaultDays - 1);

        if ($fromCarbon->gt($toCarbon)) {
            [$fromCarbon, $toCarbon] = [$toCarbon->copy(), $fromCarbon->copy()];
        }

        $span = $fromCarbon->diffInDays($toCarbon) + 1;
        if ($span > $maxDays) {
            $fromCarbon = $toCarbon->copy()->subDays($maxDays - 1);
            $filters['date_range_clamped'] = true;
        }

        $filters['from_date'] = $fromCarbon->toDateString();
        $filters['to_date'] = $toCarbon->toDateString();

        return $filters;
    }

    protected static function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
