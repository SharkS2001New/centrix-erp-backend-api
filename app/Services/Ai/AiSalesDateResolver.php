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
            if (Carbon::parse($fromDay, $tz)->diffInDays(Carbon::parse($toDay, $tz)) > 90) {
                $fromDay = Carbon::parse($toDay, $tz)->subDays(90)->toDateString();
            }

            return [$fromDay, $toDay];
        }

        $today = $now->toDateString();

        return [$today, $today];
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
