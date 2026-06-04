<?php

namespace App\Support;

class AttendanceTime
{
    /** Normalize HTML time / API input to HH:MM:SS for MySQL TIME. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (preg_match('/^\d{1,2}:\d{2}$/', $value)) {
            return $value.':00';
        }
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public static function normalizePayload(array $data): array
    {
        if (array_key_exists('check_in', $data)) {
            $data['check_in'] = self::normalize($data['check_in'] ?? null);
        }
        if (array_key_exists('check_out', $data)) {
            $data['check_out'] = self::normalize($data['check_out'] ?? null);
        }

        return $data;
    }
}
