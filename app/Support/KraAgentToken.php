<?php

namespace App\Support;

/**
 * Sanctum tokens for CentrixKraAgent (shop PC → cloud bridge).
 * Never expire / survive re-login so fiscalization keeps working unattended.
 */
final class KraAgentToken
{
    public const NAME_PREFIX = 'kra-agent:';

    public static function nameForOrganization(int $organizationId): string
    {
        return self::NAME_PREFIX.'org:'.$organizationId;
    }

    public static function isAgentTokenName(?string $name): bool
    {
        return is_string($name) && str_starts_with($name, self::NAME_PREFIX);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public static function excludeFromQuery($query)
    {
        return $query->where('name', 'not like', self::NAME_PREFIX.'%');
    }
}
