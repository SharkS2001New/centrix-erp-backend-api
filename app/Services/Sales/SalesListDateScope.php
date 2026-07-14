<?php

namespace App\Services\Sales;

use App\Support\EffectiveSaleDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps backoffice /sales list queries bounded so MySQL does not scan the full sales table.
 */
class SalesListDateScope
{
    public const MAX_RANGE_DAYS = 90;

    /**
     * @return array{
     *   from: ?string,
     *   to: ?string,
     *   applied: bool,
     *   skipped_for_search: bool,
     *   from_archive: bool,
     *   hot_window_days: int
     * }
     */
    public function apply(
        Builder $query,
        ?string $fromDate,
        ?string $toDate,
        string $dateField,
        ?string $search,
        int $hotWindowDays,
    ): array {
        $hotWindowDays = max(1, min(self::MAX_RANGE_DAYS, $hotWindowDays));
        $search = trim((string) $search);
        // Only unbounded exact order-number lookup skips the date window (digits-only tokens).
        // Free-text / name search stays inside the hot window to avoid full-table LIKE scans.
        $skippedForSearch = $search !== '' && $this->isExactOrderNumberLookup($search);

        if ($skippedForSearch) {
            return [
                'from' => null,
                'to' => null,
                'applied' => false,
                'skipped_for_search' => true,
                'from_archive' => true,
                'hot_window_days' => $hotWindowDays,
            ];
        }

        $to = $this->normalizeDate($toDate) ?? now()->toDateString();
        $from = $this->normalizeDate($fromDate);

        if ($from === null) {
            $from = Carbon::parse($to)->subDays($hotWindowDays - 1)->toDateString();
        }

        // Cap very wide ranges so a mistaken filter cannot force a multi-year scan.
        $fromCarbon = Carbon::parse($from)->startOfDay();
        $toCarbon = Carbon::parse($to)->startOfDay();
        if ($fromCarbon->gt($toCarbon)) {
            [$fromCarbon, $toCarbon] = [$toCarbon, $fromCarbon];
            $from = $fromCarbon->toDateString();
            $to = $toCarbon->toDateString();
        }

        if ($fromCarbon->diffInDays($toCarbon) + 1 > self::MAX_RANGE_DAYS) {
            $fromCarbon = $toCarbon->copy()->subDays(self::MAX_RANGE_DAYS - 1);
            $from = $fromCarbon->toDateString();
        }

        $hotFrom = now()->subDays($hotWindowDays - 1)->toDateString();
        $fromArchive = $from < $hotFrom;

        $field = strtolower(trim($dateField));
        if ($field === 'created_at' || $field === 'placed') {
            $this->applyCreatedAtRange($query, $from, $to);
        } else {
            EffectiveSaleDate::applyFromToDateFilter($query, $from, $to);
        }

        return [
            'from' => $from,
            'to' => $to,
            'applied' => true,
            'skipped_for_search' => false,
            'from_archive' => $fromArchive,
            'hot_window_days' => $hotWindowDays,
        ];
    }

    public function applyCreatedAtRange(Builder $query, string $from, string $to, string $alias = 'sales'): void
    {
        $fromTs = Carbon::parse($from)->startOfDay();
        $toExclusive = Carbon::parse($to)->startOfDay()->addDay();

        $query->where("{$alias}.created_at", '>=', $fromTs->toDateTimeString())
            ->where("{$alias}.created_at", '<', $toExclusive->toDateTimeString());
    }

    protected function normalizeDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Digits-only (optionally with spaces/dashes/hash) — treat as order_num lookup of any age.
     */
    protected function isExactOrderNumberLookup(string $search): bool
    {
        $compact = preg_replace('/[\s#\-]+/', '', $search) ?? '';

        return $compact !== '' && ctype_digit($compact) && strlen($compact) >= 3;
    }
}
