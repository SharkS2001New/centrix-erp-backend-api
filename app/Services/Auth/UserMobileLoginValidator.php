<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Mobile\MobileAppModuleAccessService;
use Illuminate\Validation\ValidationException;

class UserMobileLoginValidator
{
    public function __construct(
        protected UserLoginChannelService $loginChannels,
        protected MobileAppModuleAccessService $mobileApp,
    ) {}

    /** @param  list<string>  $loginChannels */
    public function assertMobileChannelAllowedForUser(Organization $organization, array $loginChannels, ?int $roleId, bool $isAdmin): void
    {
        $normalized = $this->loginChannels->normalize($loginChannels);
        if (! in_array(UserLoginChannelService::MOBILE, $normalized, true)) {
            return;
        }

        $gate = app(CapabilityGate::class)->forOrganization($organization);
        $allowedOrgChannels = $gate->allowedLoginChannels();
        if (! in_array(UserLoginChannelService::MOBILE, $allowedOrgChannels, true)) {
            throw ValidationException::withMessages([
                'login_channels' => ['Mobile app access is not enabled for this organization.'],
            ]);
        }

        $this->assertRoleHasMobileModuleAccess($organization, $loginChannels, $roleId, $isAdmin);
    }

    public function assertCanLoginViaMobile(User $user): void
    {
        $organization = $user->organization ?? Organization::query()->find($user->organization_id);
        if (! $organization) {
            throw ValidationException::withMessages([
                'login_channel' => ['Organization not found for this account.'],
            ]);
        }

        $gate = app(CapabilityGate::class)->forOrganization($organization);
        $allowedOrgChannels = $gate->allowedLoginChannels();
        if (! in_array(UserLoginChannelService::MOBILE, $allowedOrgChannels, true)) {
            throw ValidationException::withMessages([
                'login_channel' => ['Mobile app access is not enabled for this organization.'],
            ]);
        }

        $this->assertUserHasMobileModuleAccess($user, $gate);
    }

    protected function assertUserHasMobileModuleAccess(User $user, CapabilityGate $gate): void
    {
        $modules = $this->mobileApp->capabilitiesForUser($user, $gate);
        $salesOk = (bool) ($modules['modules']['sales']['accessible'] ?? false);
        $driverOk = (bool) ($modules['modules']['driver']['accessible'] ?? false);

        if ($salesOk || $driverOk) {
            return;
        }

        throw ValidationException::withMessages([
            'login_channel' => [
                'This account is not authorized for the mobile sales or driver app.',
            ],
        ]);
    }

    /** @param  list<string>  $loginChannels */
    protected function assertRoleHasMobileModuleAccess(Organization $organization, array $loginChannels, ?int $roleId, bool $isAdmin): void
    {
        $normalized = $this->loginChannels->normalize($loginChannels);
        if (! in_array(UserLoginChannelService::MOBILE, $normalized, true)) {
            return;
        }

        $gate = app(CapabilityGate::class)->forOrganization($organization);
        $user = new User([
            'organization_id' => $organization->id,
            'role_id' => $roleId,
            'is_admin' => $isAdmin,
        ]);

        $modules = $this->mobileApp->capabilitiesForUser($user, $gate);
        $salesOk = (bool) ($modules['modules']['sales']['accessible'] ?? false);
        $driverOk = (bool) ($modules['modules']['driver']['accessible'] ?? false);

        if ($salesOk || $driverOk) {
            return;
        }

        throw ValidationException::withMessages([
            'login_channels' => [
                'Mobile login requires Sales rep (sales.create) or Driver (driver.mobile) permission on the user\'s role.',
            ],
        ]);
    }
}
