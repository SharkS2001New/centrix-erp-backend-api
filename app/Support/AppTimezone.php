<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Application reporting timezone (East Africa Time).
 * Calendar dates and report windows are interpreted in this zone regardless of server OS timezone.
 */
class AppTimezone
{
    public const DEFAULT = 'Africa/Nairobi';

    public static function name(): string
    {
        $tz = trim((string) config('app.timezone', self::DEFAULT));

        return $tz !== '' ? $tz : self::DEFAULT;
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::name());
    }

    public static function todayDateString(): string
    {
        return self::now()->toDateString();
    }

    /** Start of a calendar day (YYYY-MM-DD) in the application timezone. */
    public static function parseDateStart(string $date): Carbon
    {
        return self::parseCalendarDate($date)->startOfDay();
    }

    /** End of a calendar day (YYYY-MM-DD) in the application timezone. */
    public static function parseDateEnd(string $date): Carbon
    {
        return self::parseCalendarDate($date)->endOfDay();
    }

    public static function parseCalendarDate(string $date): Carbon
    {
        $normalized = trim($date);
        if ($normalized === '') {
            throw new InvalidArgumentException('Date is required.');
        }

        return Carbon::parse($normalized, self::name());
    }

    /**
     * @return array{
     *     from: Carbon,
     *     to: Carbon,
     *     prev_from: Carbon,
     *     prev_to: Carbon,
     *     days: int
     * }
     */
    public static function reportPeriod(
        ?string $fromDate,
        ?string $toDate,
        int $defaultDays = 30,
    ): array {
        $to = $toDate !== null && $toDate !== ''
            ? self::parseDateEnd($toDate)
            : self::now()->endOfDay();

        $from = $fromDate !== null && $fromDate !== ''
            ? self::parseDateStart($fromDate)
            : $to->copy()->subDays(max(0, $defaultDays - 1))->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        return [
            'from' => $from,
            'to' => $to,
            'prev_from' => $prevFrom,
            'prev_to' => $prevTo,
            'days' => $days,
        ];
    }

    /**
     * Normalize API / MySQL datetime strings into a Carbon instance in the application timezone.
     */
    public static function normalize(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->copy()->timezone(self::name());
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->timezone(self::name());
        }

        if (is_numeric($value)) {
            $numeric = (float) $value;
            $seconds = $numeric < 1_000_000_000_000 ? (int) $numeric : (int) round($numeric / 1000);

            return Carbon::createFromTimestamp($seconds, self::name());
        }

        $input = trim((string) $value);
        if ($input === '') {
            return null;
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}/', $input) === 1) {
            [$datePart, $timePart] = array_pad(explode(' ', $input, 2), 2, '00:00:00');
            [$day, $month, $year] = explode('/', $datePart);
            $time = strlen($timePart) === 5 ? $timePart.':00' : $timePart;
            $input = sprintf('%s-%s-%sT%s', $year, $month, $day, $time);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $input) === 1) {
            $input = str_replace(' ', 'T', $input);
            if (preg_match('/T\d{2}:\d{2}$/', $input) === 1) {
                $input .= ':00';
            }
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) === 1) {
                return self::parseDateStart($input);
            }

            if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $input) === 1) {
                return Carbon::parse($input)->timezone(self::name());
            }

            return Carbon::parse($input, self::name());
        } catch (\Throwable) {
            return null;
        }
    }

    public static function formatDateTime(
        mixed $value,
        string $format = 'd M Y H:i',
    ): ?string {
        $date = self::normalize($value);

        return $date?->format($format);
    }

    public static function toIso8601(mixed $value): ?string
    {
        $date = self::normalize($value);

        return $date?->toIso8601String();
    }
}
