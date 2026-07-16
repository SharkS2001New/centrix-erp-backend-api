<?php

namespace App\Services\Cache;

use App\Models\Role;
use App\Models\User;

class CapabilitiesCacheInvalidator
{
    public static function forOrganization(?int $organizationId): void
    {
        if ($organizationId === null || $organizationId <= 0) {
            return;
        }

        OrganizationCache::invalidateCapabilities($organizationId);
    }

    public static function forUser(User $user): void
    {
        self::forOrganization($user->organization_id ? (int) $user->organization_id : null);
    }

    public static function forRole(Role $role): void
    {
        $orgIds = User::query()
            ->where('role_id', $role->id)
            ->distinct()
            ->pluck('organization_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $membershipOrgIds = \App\Models\UserMembership::query()
            ->where('role_id', $role->id)
            ->distinct()
            ->pluck('organization_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $orgIds = array_merge($orgIds, $membershipOrgIds);

        if ($role->organization_id) {
            $orgIds[] = (int) $role->organization_id;
        }

        foreach (array_unique($orgIds) as $organizationId) {
            self::forOrganization($organizationId);
        }
    }
}
