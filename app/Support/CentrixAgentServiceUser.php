<?php

namespace App\Support;

/**
 * Shared helpers for org-owned Centrix agent machine accounts (KRA, attendance, …).
 * These are not real company users and must not appear in backoffice user pickers.
 */
final class CentrixAgentServiceUser
{
    /** @var list<string> */
    public const USERNAME_PREFIXES = [
        '__CENTRIX_KRA_AGENT_',
        '__CENTRIX_ATTENDANCE_AGENT_',
    ];

    public static function isServiceUsername(?string $username): bool
    {
        if (! is_string($username) || $username === '') {
            return false;
        }

        $upper = strtoupper($username);
        foreach (self::USERNAME_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exclude Centrix agent machine accounts from a users query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  string  $usernameColumn  Qualified column when querying joined tables (e.g. users.username)
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public static function excludeFromQuery($query, string $usernameColumn = 'username')
    {
        foreach (self::USERNAME_PREFIXES as $prefix) {
            $query->where($usernameColumn, 'not like', $prefix.'%');
        }

        return $query;
    }
}
