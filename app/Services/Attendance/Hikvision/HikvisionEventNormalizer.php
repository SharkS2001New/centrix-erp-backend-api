<?php

namespace App\Services\Attendance\Hikvision;

use App\Models\HikvisionAccessEvent;

/**
 * Hikvision ISAPI often sends the literal strings "undefined" / "null"
 * (and omits attendanceStatus / verify mode). Map those into Centrix fields.
 */
final class HikvisionEventNormalizer
{
    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function normalizeIncoming(array $event): array
    {
        $raw = is_array($event['raw'] ?? null) ? $event['raw'] : [];

        $minor = self::intOrNull($event['minor'] ?? $raw['minor'] ?? null);
        $major = self::intOrNull($event['major'] ?? $raw['major'] ?? null);

        $event['employee_name'] = self::usableString(
            $event['employee_name'] ?? null,
            $raw['name'] ?? null,
            $raw['Name'] ?? null,
        );
        $event['card_no'] = self::usableString(
            $event['card_no'] ?? null,
            $raw['cardNo'] ?? null,
            $raw['CardNo'] ?? null,
        );
        $event['serial_no'] = self::usableString(
            $event['serial_no'] ?? null,
            $raw['serialNo'] ?? null,
            $raw['SerialNo'] ?? null,
        );
        $event['attendance_status'] = self::mapAttendanceStatus($event, $raw, $minor);
        $event['verification_method'] = self::mapVerifyMode($event, $raw, $minor);
        $event['major'] = $major;
        $event['minor'] = $minor;

        return $event;
    }

    public static function present(HikvisionAccessEvent $row): HikvisionAccessEvent
    {
        $enriched = self::normalizeIncoming([
            'attendance_status' => $row->getRawOriginal('attendance_status') ?? $row->attendance_status,
            'verification_method' => $row->getRawOriginal('verification_method') ?? $row->verification_method,
            'employee_name' => $row->getRawOriginal('employee_name') ?? $row->employee_name,
            'card_no' => $row->getRawOriginal('card_no') ?? $row->card_no,
            'serial_no' => $row->getRawOriginal('serial_no') ?? $row->serial_no,
            'major' => $row->major,
            'minor' => $row->minor,
            'raw' => is_array($row->raw_payload) ? $row->raw_payload : [],
        ]);

        $row->setAttribute('attendance_status', $enriched['attendance_status']);
        $row->setAttribute('verification_method', $enriched['verification_method']);
        $row->setAttribute('employee_name', $enriched['employee_name']);
        $row->setAttribute('card_no', $enriched['card_no']);
        $row->setAttribute('serial_no', $enriched['serial_no']);
        $row->setAttribute('major', $enriched['major']);
        $row->setAttribute('minor', $enriched['minor']);

        return $row;
    }

    public static function usableString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text === '' || strcasecmp($text, 'undefined') === 0 || strcasecmp($text, 'null') === 0) {
                continue;
            }

            return $text;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $raw
     */
    protected static function mapAttendanceStatus(array $event, array $raw, ?int $minor): ?string
    {
        $rawStatus = self::usableString(
            $event['attendance_status'] ?? null,
            $raw['attendanceStatus'] ?? null,
            $raw['AttendanceStatus'] ?? null,
            $raw['status'] ?? null,
            $raw['label'] ?? null,
        );
        if ($rawStatus !== null) {
            return $rawStatus;
        }
        if ($minor === 75) {
            return 'checkIn';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $raw
     */
    protected static function mapVerifyMode(array $event, array $raw, ?int $minor): ?string
    {
        $rawMode = self::usableString(
            $event['verification_method'] ?? null,
            $raw['currentVerifyMode'] ?? null,
            $raw['CurrentVerifyMode'] ?? null,
            $raw['verifyMode'] ?? null,
            $raw['VerifyMode'] ?? null,
        );
        if ($rawMode !== null && preg_match('/finger|card|face|iris|password|pin|pw/i', $rawMode) === 1) {
            return strtolower($rawMode);
        }

        $n = is_numeric($rawMode) ? (int) $rawMode : null;
        $byNumber = [
            1 => 'card',
            2 => 'fingerprint',
            3 => 'card',
            4 => 'fingerprint',
            5 => 'card+fingerprint',
            8 => 'face',
            15 => 'fingerprint',
        ];
        if ($n !== null && isset($byNumber[$n])) {
            return $byNumber[$n];
        }
        if ($rawMode !== null) {
            return strtolower($rawMode);
        }
        if ($minor === 75) {
            return 'fingerprint';
        }

        return null;
    }

    protected static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
