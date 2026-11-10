<?php

namespace App\Services\Mobile;

use App\Models\User;
use App\Services\Auth\UserLoginChannelService;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Sales\MobileFieldAttendanceService;

class MobileAppModuleAccessService
{
    public function __construct(
        protected UserPermissionService $permissions,
        protected MobileFieldAttendanceService $fieldAttendance,
    ) {}

    /** @return array<string, mixed> */
    public function capabilitiesForUser(User $user, CapabilityGate $gate): array
    {
        $isAdmin = (bool) $user->is_admin;
        // CentrixAsMobile channel unlocks AS shop even when field sales.mobile module is off.
        $asMobileChannel = $this->userHasCentrixAsMobileChannel($user);
        $salesOrgEnabled = $gate->mobileSalesEnabled() || $asMobileChannel;
        $driverOrgEnabled = $gate->driverMobileEnabled();
        $driverAttendanceOrgEnabled = $gate->driverAttendanceEnabled();
        $fieldAttendanceOrgEnabled = $this->fieldAttendance->isEnabled($gate);

        // CentrixAsMobile login channel checkbox unlocks AS shop; Roles grant still works too.
        // Field sales app still uses sales.create / mobile_sales.*.
        $salesUserAllowed = $isAdmin
            || $asMobileChannel
            || $this->permissions->hasPermission($user, 'centrix_as_mobile.app.access', $gate)
            || $this->permissions->hasPermission($user, 'sales.create', $gate)
            || $this->permissions->hasPermission($user, 'mobile_sales.orders.create', $gate);
        $driverUserAllowed = $isAdmin || $this->permissions->hasPermission($user, 'driver.mobile', $gate);

        $sales = $this->modulePayload(
            orgEnabled: $salesOrgEnabled,
            userAllowed: $salesUserAllowed,
            orgDisabledMessage: 'Mobile sales is not enabled for your organization. Contact your platform administrator.',
            userDisabledMessage: 'Enable CentrixAsMobile on Allowed login channels (or grant Centrix AS Mobile on the role).',
        );

        $driver = $this->modulePayload(
            orgEnabled: $driverOrgEnabled,
            userAllowed: $driverUserAllowed,
            orgDisabledMessage: 'The driver module is not enabled for your organization. Contact your platform administrator.',
            userDisabledMessage: 'Your account is not authorized for the driver module.',
        );

        if (! $isAdmin) {
            if (! $sales['accessible'] && ! $driver['accessible']) {
                $sales['disabled_message'] = $sales['disabled_message'] ?? $driver['disabled_message']
                    ?? 'Mobile access is limited to field sales and driver modules for your account.';
            }
        }

        return [
            'modules_locked' => ! $isAdmin,
            'mobile_orders_enabled' => $salesOrgEnabled,
            'driver_mobile_enabled' => $driverOrgEnabled,
            'driver_attendance_enabled' => $driverAttendanceOrgEnabled,
            'field_attendance_enabled' => $fieldAttendanceOrgEnabled,
            'modules' => [
                'sales' => $sales,
                'driver' => $driver,
            ],
        ];
    }

    protected function userHasCentrixAsMobileChannel(User $user): bool
    {
        try {
            $channels = app(UserLoginChannelService::class)->channelsFor($user);

            return in_array(UserLoginChannelService::CENTRIX_AS_MOBILE, $channels, true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function assertSalesAccess(User $user, CapabilityGate $gate): void
    {
        $module = $this->capabilitiesForUser($user, $gate)['modules']['sales'];
        if (! ($module['accessible'] ?? false)) {
            abort(403, (string) ($module['disabled_message'] ?? 'Mobile sales is not available for your account.'));
        }
    }

    public function assertDriverAccess(User $user, CapabilityGate $gate): void
    {
        $module = $this->capabilitiesForUser($user, $gate)['modules']['driver'];
        if (! ($module['accessible'] ?? false)) {
            abort(403, (string) ($module['disabled_message'] ?? 'The driver module is not available for your account.'));
        }
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
