<?php

namespace App\Support;

/**
 * Sanctum personal-access tokens issued for CentrixAttendanceAgent downloads.
 * These must never expire and must survive normal user re-login / org session
 * revocation so offices do not re-download the agent every morning. The agent
 * checks in and syncs attendance unattended — no browser login required.
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

    /**
     * Constrain a personal_access_tokens query to exclude attendance-agent tokens.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public static function excludeFromQuery($query)
    {
        return $query->where('name', 'not like', self::NAME_PREFIX.'%');
    }
}
