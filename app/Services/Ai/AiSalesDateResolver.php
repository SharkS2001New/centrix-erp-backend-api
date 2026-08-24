<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Services\Erp\GeneralSettingsResolver;
use App\Support\AppTimezone;
use Carbon\Carbon;

/**
 * Calendar-day resolution for AI sales tools — matches Centrix report timezone semantics.
 */
class AiSalesDateResolver
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: string}
     */
    public static function resolve(array $arguments, ?Organization $organization = null): array
    {
        $tz = self::timezone($organization);
        $now = Carbon::now($tz);

        $relative = strtolower(trim((string) ($arguments['relative_date'] ?? '')));
        if ($relative === 'today') {
            $day = $now->toDateString();

            return [$day, $day];
        }
        if ($relative === 'yesterday') {
            $day = $now->copy()->subDay()->toDateString();

            return [$day, $day];
        }
        if ($relative === 'last_7_days') {
            return [
                $now->copy()->subDays(6)->toDateString(),
                $now->toDateString(),
            ];
        }
        if ($relative === 'this_month' || $relative === 'this_month_to_date') {
            $start = $now->copy()->startOfMonth()->toDateString();
            $end = $relative === 'this_month_to_date'
                ? $now->toDateString()
                : $now->copy()->endOfMonth()->toDateString();

            return [$start, $end];
        }
        if ($relative === 'last_month') {
            $last = $now->copy()->subMonthNoOverflow();

            return [
                $last->copy()->startOfMonth()->toDateString(),
                $last->copy()->endOfMonth()->toDateString(),
            ];
        }

        $yearMonth = trim((string) ($arguments['year_month'] ?? ''));
        if ($yearMonth !== '' && preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $m)) {
            $start = Carbon::createFromDate((int) $m[1], (int) $m[2], 1, $tz)->startOfMonth();

            return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
        }

        $monthArg = trim((string) ($arguments['month'] ?? ''));
        $yearArg = (int) ($arguments['year'] ?? 0);
        if ($monthArg !== '') {
            $monthNum = self::parseMonthNumber($monthArg);
            if ($monthNum !== null) {
                $year = $yearArg > 2000 ? $yearArg : (int) $now->year;
                // If named month is ahead of current month and year omitted, assume previous calendar year.
                if ($yearArg <= 0 && $monthNum > (int) $now->month) {
                    $year = (int) $now->copy()->subYear()->year;
                }
                $start = Carbon::createFromDate($year, $monthNum, 1, $tz)->startOfMonth();

                return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
            }
        }

        $date = trim((string) ($arguments['date'] ?? ''));
        $from = trim((string) ($arguments['from_date'] ?? ''));
        $to = trim((string) ($arguments['to_date'] ?? ''));

        if ($date !== '') {
            $day = Carbon::parse($date, $tz)->toDateString();

            return [$day, $day];
        }

        if ($from !== '' && $to !== '') {
            $fromDay = Carbon::parse($from, $tz)->toDateString();
            $toDay = Carbon::parse($to, $tz)->toDateString();
            if ($fromDay > $toDay) {
                [$fromDay, $toDay] = [$toDay, $fromDay];
            }
            if (Carbon::parse($fromDay, $tz)->diffInDays(Carbon::parse($toDay, $tz)) > 366) {
                $fromDay = Carbon::parse($toDay, $tz)->subDays(366)->toDateString();
            }

            return [$fromDay, $toDay];
        }

        $today = $now->toDateString();

        return [$today, $today];
    }

    /** @return int|null 1–12 */
    public static function parseMonthNumber(string $month): ?int
    {
        $raw = strtolower(trim($month));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{1,2}$/', $raw)) {
            $n = (int) $raw;

            return ($n >= 1 && $n <= 12) ? $n : null;
        }
        $map = [
            'jan' => 1, 'january' => 1,
            'feb' => 2, 'february' => 2,
            'mar' => 3, 'march' => 3,
            'apr' => 4, 'april' => 4,
            'may' => 5,
            'jun' => 6, 'june' => 6,
            'jul' => 7, 'july' => 7,
            'aug' => 8, 'august' => 8,
            'sep' => 9, 'sept' => 9, 'september' => 9,
            'oct' => 10, 'october' => 10,
            'nov' => 11, 'november' => 11,
            'dec' => 12, 'december' => 12,
        ];

        return $map[$raw] ?? null;
    }

    /** @return array{timezone: string, today: string, yesterday: string} */
    public static function calendarAnchor(?Organization $organization = null): array
    {
        $tz = self::timezone($organization);
        $now = Carbon::now($tz);

        return [
            'timezone' => $tz,
            'today' => $now->toDateString(),
            'yesterday' => $now->copy()->subDay()->toDateString(),
        ];
    }

    protected static function timezone(?Organization $organization): string
    {
        if ($organization) {
            $tz = trim((string) (GeneralSettingsResolver::forOrganization($organization)['timezone'] ?? ''));
            if ($tz !== '') {
                return $tz;
            }
        }

        return AppTimezone::name();
    }
}
