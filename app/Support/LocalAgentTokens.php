<?php

namespace App\Support;

/**
 * Personal-access tokens issued for on-prem Centrix agents (attendance, KRA, …).
 */
final class LocalAgentTokens
{
    public static function isAnyAgentTokenName(?string $name): bool
    {
        return AttendanceAgentToken::isAgentTokenName($name)
            || KraAgentToken::isAgentTokenName($name);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public static function excludeFromQuery($query)
    {
        return $query
            ->where('name', 'not like', AttendanceAgentToken::NAME_PREFIX.'%')
            ->where('name', 'not like', KraAgentToken::NAME_PREFIX.'%');
    }
}
