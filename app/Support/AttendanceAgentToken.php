<?php

namespace App\Support;

/**
 * Sanctum personal-access tokens issued for CentrixAttendanceAgent downloads.
 * These must survive normal user re-login / org session revocation so offices
 * do not re-download the agent every morning.
 */
final class AttendanceAgentToken
{
    public const NAME_PREFIX = 'attendance-agent:';

    public static function nameForDevice(string $deviceNo): string
    {
        return self::NAME_PREFIX.$deviceNo;
    }

    public static function isAgentTokenName(?string $name): bool
    {
        return is_string($name) && str_starts_with($name, self::NAME_PREFIX);
    }
}
