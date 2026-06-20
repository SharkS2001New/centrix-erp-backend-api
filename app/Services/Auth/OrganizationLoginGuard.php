<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OrganizationLoginGuard
{
    public function assertOrganizationAllowsLogin(Organization $organization, ?User $user = null): void
    {
        if ($user?->is_super_admin || $this->isPlatformOrganization($organization)) {
            return;
        }

        if ($organization->is_active === false) {
            throw ValidationException::withMessages([
                'username' => ['This organization account has been disabled. Please contact your platform administrator.'],
            ]);
        }

        $hasActiveAdmin = User::query()
            ->where('organization_id', $organization->id)
            ->where('is_admin', true)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasActiveAdmin) {
            throw ValidationException::withMessages([
                'username' => ['Sign-in is unavailable because the organization administrator account is disabled.'],
            ]);
        }
    }

    protected function isPlatformOrganization(Organization $organization): bool
    {
        $platformCode = strtoupper((string) config('erp.platform_company_code', 'PLATFORM'));

        return strtoupper((string) $organization->company_code) === $platformCode
            || (bool) ($organization->module_settings['platform'] ?? false);
    }
}
