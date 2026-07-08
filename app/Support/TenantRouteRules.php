<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class TenantRouteRules
{
    public static function exists(?int $organizationId)
    {
        $rule = Rule::exists('routes', 'id');
        if ($organizationId) {
            $rule->where('organization_id', $organizationId);
        }

        return $rule;
    }

    /** @return array<int, mixed> */
    public static function nullable(?int $organizationId): array
    {
        return ['nullable', 'integer', self::exists($organizationId)];
    }

    /** @return array<int, mixed> */
    public static function required(?int $organizationId): array
    {
        return ['required', 'integer', self::exists($organizationId)];
    }

    /** @return array<int, mixed> */
    public static function each(?int $organizationId): array
    {
        return ['integer', self::exists($organizationId)];
    }
}
