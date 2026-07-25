<?php

namespace App\Services\Pos;

use Illuminate\Validation\ValidationException;

/**
 * Branch tills are Till01–Till10. Locked tills (cashier_id set) are never auto-assigned.
 */
class TillNumbering
{
    public const MAX_TILL_NUMBER = 10;

    public static function parseNumber(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }
        if (preg_match('/^Till(\d+)$/i', trim($value), $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public static function label(int $n): string
    {
        return 'Till'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  iterable<int, object|array<string, mixed>>  $rows
     * @return array<int, true>
     */
    public static function usedNumbers(iterable $rows): array
    {
        $used = [];
        foreach ($rows as $row) {
            $name = is_array($row) ? ($row['till_name'] ?? null) : ($row->till_name ?? null);
            $number = is_array($row) ? ($row['till_number'] ?? null) : ($row->till_number ?? null);
            foreach ([$name, $number] as $value) {
                $n = self::parseNumber($value);
                if ($n !== null && $n >= 1 && $n <= self::MAX_TILL_NUMBER) {
                    $used[$n] = true;
                }
            }
        }

        return $used;
    }

    /**
     * Lowest free Till01–Till10 label, or null when all slots exist.
     *
     * @param  iterable<int, object|array<string, mixed>>  $rows
     */
    public static function nextLabelOrNull(iterable $rows): ?string
    {
        $used = self::usedNumbers($rows);
        for ($i = 1; $i <= self::MAX_TILL_NUMBER; $i++) {
            if (! isset($used[$i])) {
                return self::label($i);
            }
        }

        return null;
    }

    /**
     * @param  iterable<int, object|array<string, mixed>>  $rows
     */
    public static function nextLabelOrFail(iterable $rows, string $field = 'till_id'): string
    {
        $label = self::nextLabelOrNull($rows);
        if ($label === null) {
            throw ValidationException::withMessages([
                $field => ['All tills Till01–Till10 are already in use at this branch. Unlock or reassign a till first.'],
            ]);
        }

        return $label;
    }

    public static function sortKey(mixed $till): int
    {
        $name = is_array($till) ? ($till['till_name'] ?? null) : ($till->till_name ?? null);
        $number = is_array($till) ? ($till['till_number'] ?? null) : ($till->till_number ?? null);
        $n = self::parseNumber(is_string($name) ? $name : null)
            ?? self::parseNumber(is_string($number) ? $number : null);

        return $n ?? 999;
    }
}
