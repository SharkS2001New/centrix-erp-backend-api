<?php

namespace App\Services\Mobile;

use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;

class ManagerAppModuleAccessService
{
    public function __construct(
        protected UserPermissionService $permissions,
    ) {}

    /** @return array<string, mixed> */
    public function capabilitiesForUser(User $user, CapabilityGate $gate): array
    {
        $isAdmin = (bool) $user->is_admin;
        $orgEnabled = $gate->managerAppEnabled();
        $userAllowed = $isAdmin || $this->permissions->hasPermission($user, 'mobile_manager.app.access', $gate);

        return $this->modulePayload(
            orgEnabled: $orgEnabled,
            userAllowed: $userAllowed,
            orgDisabledMessage: 'The Centrix Manager app is not enabled for your organization.',
            userDisabledMessage: 'Your account is not authorized for the Centrix Manager app.',
        );
    }

    public function assertManagerAccess(User $user, CapabilityGate $gate): void
    {
        $module = $this->capabilitiesForUser($user, $gate);
        if (! ($module['accessible'] ?? false)) {
            abort(403, (string) ($module['disabled_message'] ?? 'The Centrix Manager app is not available for your account.'));
        }
    }

    public function assertReportsAccess(User $user, CapabilityGate $gate): void
    {
        if ($user->is_admin) {
            return;
        }

        if ($this->permissions->hasPermission($user, 'mobile_manager.reports.view', $gate)) {
            return;
        }

        if ($this->permissions->hasPermission($user, 'mobile_manager.app.access', $gate)) {
            return;
        }

        abort(403, 'You do not have permission to view reports in Centrix Manager.');
    }

    /**
     * @return array{org_enabled: bool, user_allowed: bool, accessible: bool, disabled_message: ?string}
     */
    protected function modulePayload(
        bool $orgEnabled,
        bool $userAllowed,
        string $orgDisabledMessage,
        string $userDisabledMessage,
    ): array {
        $accessible = $orgEnabled && $userAllowed;
        $disabledMessage = null;

        if (! $orgEnabled) {
            $disabledMessage = $orgDisabledMessage;
        } elseif (! $userAllowed) {
            $disabledMessage = $userDisabledMessage;
        }

        return [
            'org_enabled' => $orgEnabled,
            'user_allowed' => $userAllowed,
            'accessible' => $accessible,
            'disabled_message' => $disabledMessage,
        ];
    }
}
