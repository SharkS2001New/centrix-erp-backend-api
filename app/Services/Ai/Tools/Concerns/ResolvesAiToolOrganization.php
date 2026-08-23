<?php

namespace App\Services\Ai\Tools\Concerns;

use App\Models\Organization;
use App\Models\User;

trait ResolvesAiToolOrganization
{
    protected function resolveOrganizationForUser(User $user): ?Organization
    {
        $request = request();
        $actingId = $request->attributes->get('acting_organization_id');
        if ($actingId && ($request->user()?->id === $user->id || $user->is_super_admin)) {
            $acting = Organization::query()->find((int) $actingId);
            if ($acting) {
                return $acting;
            }
        }

        return Organization::query()->find((int) $user->organization_id);
    }

    protected function assertSameOrganization(User $user, Organization $organization): bool
    {
        return (int) $user->organization_id === (int) $organization->id || (bool) $user->is_super_admin;
    }
}
