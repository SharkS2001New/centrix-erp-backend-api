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

    /** Fallback when platform has not configured a search window. */
    public const DEFAULT_SEARCH_WINDOW_DAYS = 30;

    /**
     * @return array{
     *   from: ?string,
     *   to: ?string,
     *   applied: bool,
     *   skipped_for_search: bool,
     *   from_archive: bool,
     *   hot_window_days: int,
     *   search_window: bool
     * }
     */
    public function apply(
        Builder $query,
        ?string $fromDate,
        ?string $toDate,
        string $dateField,
        ?string $search,
        int $hotWindowDays,
        ?int $searchWindowDays = null,
    ): array {
        $hotWindowDays = max(1, min(self::MAX_RANGE_DAYS, $hotWindowDays));
        $searchWindowDays = max(
            $hotWindowDays,
            min(self::MAX_RANGE_DAYS, (int) ($searchWindowDays ?? self::DEFAULT_SEARCH_WINDOW_DAYS)),
        );
        $search = trim((string) $search);
        $searching = $search !== '';

        // Exact order # (168 / S0168) must resolve at any age — returns and invoice lookups.
        // Free-text / customer search stays inside the platform search window.
        if ($searching && $this->isExactOrderNumberLookup($search)) {
            return [
                'from' => null,
                'to' => null,
                'applied' => false,
                'skipped_for_search' => true,
                'from_archive' => true,
                'hot_window_days' => $searchWindowDays,
                'search_window' => false,
            ];
        }

        $to = $this->normalizeDate($toDate) ?? now()->toDateString();
        $from = $this->normalizeDate($fromDate);

        if ($searching) {
            // Name / free-text search: platform search window (default 1 month).
            $searchFrom = Carbon::parse($to)->subDays($searchWindowDays - 1)->toDateString();
            if ($from === null || $from > $searchFrom) {
                $from = $searchFrom;
            }
            $effectiveHotDays = $searchWindowDays;
        } else {
            if ($from === null) {
                $from = Carbon::parse($to)->subDays($hotWindowDays - 1)->toDateString();
            }
            $effectiveHotDays = $hotWindowDays;
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

        $hotFrom = now()->subDays($effectiveHotDays - 1)->toDateString();
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
            'hot_window_days' => $effectiveHotDays,
            'search_window' => $searching,
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
     * Digits-only or S0168 / #S168 — treat as exact order_num lookup of any age.
     */
    protected function isExactOrderNumberLookup(string $search): bool
    {
        $compact = preg_replace('/[\s#\-]+/', '', $search) ?? '';
        if ($compact !== '' && ctype_digit($compact)) {
            return true;
        }

        return (bool) preg_match('/^S0*\d+$/i', $compact);
    }
}
