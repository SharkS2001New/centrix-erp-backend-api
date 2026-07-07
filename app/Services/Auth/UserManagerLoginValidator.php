<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Mobile\ManagerAppModuleAccessService;
use Illuminate\Validation\ValidationException;

class UserManagerLoginValidator
{
    public function __construct(
        protected UserLoginChannelService $loginChannels,
        protected ManagerAppModuleAccessService $managerApp,
    ) {}

    /** @param  list<string>  $loginChannels */
    public function assertManagerChannelAllowedForUser(Organization $organization, array $loginChannels, ?int $roleId, bool $isAdmin): void
    {
        $normalized = $this->loginChannels->normalize($loginChannels);
        if (! in_array(UserLoginChannelService::MANAGER, $normalized, true)) {
            return;
        }

        $gate = app(CapabilityGate::class)->forOrganization($organization);
        $allowedOrgChannels = $gate->allowedLoginChannels();
        if (! in_array(UserLoginChannelService::MANAGER, $allowedOrgChannels, true)) {
            throw ValidationException::withMessages([
                'login_channels' => ['Centrix Manager app access is not enabled for this organization.'],
            ]);
        }

        $user = new User([
            'organization_id' => $organization->id,
            'role_id' => $roleId,
            'is_admin' => $isAdmin,
        ]);

        $module = $this->managerApp->capabilitiesForUser($user, $gate);
        if (! ($module['accessible'] ?? false)) {
            throw ValidationException::withMessages([
                'login_channels' => [
                    'Manager login requires mobile_manager.app.access on the user\'s role, or an administrator account.',
                ],
            ]);
        }
    }

    public function assertCanLoginViaManager(User $user): void
    {
        $organization = $user->organization ?? Organization::query()->find($user->organization_id);
        if (! $organization) {
            throw ValidationException::withMessages([
                'login_channel' => ['Organization not found for this account.'],
            ]);
        }

        $gate = app(CapabilityGate::class)->forOrganization($organization);
        $allowedOrgChannels = $gate->allowedLoginChannels();
        if (! in_array(UserLoginChannelService::MANAGER, $allowedOrgChannels, true)) {
            throw ValidationException::withMessages([
                'login_channel' => ['Centrix Manager app access is not enabled for this organization.'],
            ]);
        }

        $module = $this->managerApp->capabilitiesForUser($user, $gate);
        if (! ($module['accessible'] ?? false)) {
            throw ValidationException::withMessages([
                'login_channel' => [
                    'This account is not authorized for the Centrix Manager app.',
                ],
            ]);
        }
    }
}
