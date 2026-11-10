<?php

namespace App\Services\Mobile;

use App\Models\User;
use App\Services\Auth\UserLoginChannelService;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\BackofficeSections;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\IndustryRegistry;
use App\Services\Hospitality\HospitalityServices;
use App\Services\Sales\MobileFieldAttendanceService;
use App\Support\SanctumTokenResolver;

class MobileAppModuleAccessService
{
    /** @var list<string> */
    public const HOME_MODULE_KEYS = [
        'menu_items',
        'vendors',
        'orders',
        'hotel_bar_pos',
        'kitchen_stock',
        'receiving',
        'past_checks',
        'expenses',
        'stock_count',
        'guests',
        'reports',
        'folio_pay',
    ];

    public function __construct(
        protected UserPermissionService $permissions,
        protected MobileFieldAttendanceService $fieldAttendance,
    ) {}

    /** @return array<string, mixed> */
    public function capabilitiesForUser(User $user, CapabilityGate $gate): array
    {
        $isAdmin = (bool) $user->is_admin;
        // CentrixAsMobile: explicit checkbox, active AS session (incl. hotel soft-allow login),
        // or org soft-allow eligibility — unlocks AS shop even when field sales.mobile is off.
        $asMobileChannel = $this->userHasCentrixAsMobileAccess($user, $gate);
        $salesOrgEnabled = $gate->mobileSalesEnabled() || $asMobileChannel;
        $driverOrgEnabled = $gate->driverMobileEnabled();
        $driverAttendanceOrgEnabled = $gate->driverAttendanceEnabled();
        $fieldAttendanceOrgEnabled = $this->fieldAttendance->isEnabled($gate);

        // CentrixAsMobile login / soft-allow unlocks AS shop; Roles grant still works too.
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

        $industry = IndustryRegistry::isHospitality($gate->organization()?->deployment_profile)
            ? 'hospitality'
            : 'commerce';

        return [
            'modules_locked' => ! $isAdmin,
            'mobile_orders_enabled' => $salesOrgEnabled,
            'driver_mobile_enabled' => $driverOrgEnabled,
            'driver_attendance_enabled' => $driverAttendanceOrgEnabled,
            'field_attendance_enabled' => $fieldAttendanceOrgEnabled,
            'industry' => $industry,
            'modules' => [
                'sales' => $sales,
                'driver' => $driver,
            ],
            // CentrixAsMobile home tiles — mirrors Platform Admin hotel/setup customization.
            'home_modules' => $this->homeModulesForUser($user, $gate, $salesUserAllowed),
        ];
    }

    /**
     * @return array<string, array{org_enabled: bool, user_allowed: bool, accessible: bool, disabled_message: ?string}>
     */
    public function homeModulesForUser(User $user, CapabilityGate $gate, ?bool $salesUserAllowed = null): array
    {
        if ($salesUserAllowed === null) {
            $isAdmin = (bool) $user->is_admin;
            $asMobileChannel = $this->userHasCentrixAsMobileAccess($user, $gate);
            $salesUserAllowed = $isAdmin
                || $asMobileChannel
                || $this->permissions->hasPermission($user, 'centrix_as_mobile.app.access', $gate)
                || $this->permissions->hasPermission($user, 'sales.create', $gate)
                || $this->permissions->hasPermission($user, 'mobile_sales.orders.create', $gate);
        }

        $org = $gate->organization();
        $sections = BackofficeSections::forOrganization($org);
        $services = HospitalityServices::forOrganization($org);
        $isHospitality = IndustryRegistry::isHospitality($org?->deployment_profile)
            || $gate->enabled('hospitality.bar_pos')
            || $gate->enabled('hospitality.backend');

        $barPos = $gate->enabled('hospitality.bar_pos');
        $hospitalityBackend = $gate->enabled('hospitality.backend');
        $hospitalityReports = $gate->enabled('hospitality.reports') || $hospitalityBackend;
        $inventory = $gate->enabled('inventory');
        $customersSuppliers = $gate->enabled('customers_suppliers') || $hospitalityBackend;

        $definitions = [
            'menu_items' => [
                'org' => ($sections['products'] ?? true)
                    && ($hospitalityBackend || $gate->enabled('sales.backend') || $inventory || $barPos),
                'off' => 'Menu / products are disabled for this organization in Platform setup.',
            ],
            'vendors' => [
                'org' => ($sections['suppliers'] ?? true) && $customersSuppliers,
                'off' => 'Vendors / suppliers are disabled for this organization in Platform setup.',
            ],
            'orders' => [
                'org' => $barPos
                    || (($sections['sales_orders'] ?? true) && ($gate->enabled('sales.backend') || $gate->enabled('sales.mobile'))),
                'off' => 'Orders are disabled for this organization in Platform setup.',
            ],
            'hotel_bar_pos' => [
                'org' => $isHospitality && $barPos,
                'off' => 'Hotel / Bar POS is disabled for this organization in Platform setup.',
            ],
            'kitchen_stock' => [
                'org' => $inventory && ($sections['inventory'] ?? true),
                'off' => 'Kitchen / bar stock is disabled for this organization in Platform setup.',
            ],
            'receiving' => [
                'org' => $inventory && ($sections['stock_movements'] ?? true),
                'off' => 'Receiving is disabled for this organization in Platform setup.',
            ],
            'past_checks' => [
                'org' => $isHospitality && $barPos,
                'off' => 'Past checks are disabled for this organization in Platform setup.',
            ],
            'expenses' => [
                'org' => ($sections['expenses'] ?? true)
                    && ($gate->enabled('accounting') || $hospitalityBackend || $gate->enabled('sales.backend')),
                'off' => 'Expenses are disabled for this organization in Platform setup.',
            ],
            'stock_count' => [
                'org' => $inventory && ($sections['inventory'] ?? true),
                'off' => 'Stock count is disabled for this organization in Platform setup.',
            ],
            'guests' => [
                'org' => ($sections['customers'] ?? true) && $customersSuppliers,
                'off' => 'Guests / customers are disabled for this organization in Platform setup.',
            ],
            'reports' => [
                'org' => ($sections['reports'] ?? true)
                    && ($hospitalityReports || $gate->enabled('sales.backend') || $gate->enabled('sales.mobile') || $barPos),
                'off' => 'Reports are disabled for this organization in Platform setup.',
            ],
            'folio_pay' => [
                'org' => $isHospitality
                    && ($barPos || $hospitalityBackend)
                    && (bool) ($services['folios'] ?? false),
                'off' => 'Folio pay is disabled for this organization in Platform hospitality services.',
            ],
        ];

        $out = [];
        foreach ($definitions as $key => $def) {
            $out[$key] = $this->modulePayload(
                orgEnabled: (bool) $def['org'],
                userAllowed: (bool) $salesUserAllowed,
                orgDisabledMessage: (string) $def['off'],
                userDisabledMessage: 'Your account is not authorized for CentrixAsMobile modules.',
            );
        }

        return $out;
    }

    /**
     * Map an API path (without api/v1/) to a CentrixAsMobile home module key.
     */
    public function homeModuleKeyForPath(string $path): ?string
    {
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'api/v1/')) {
            $path = substr($path, 7);
        }

        return match (true) {
            str_starts_with($path, 'hospitality/pos'),
            str_starts_with($path, 'hospitality/floor-tables'),
            str_starts_with($path, 'hospitality/outlets') => 'hotel_bar_pos',
            str_starts_with($path, 'hospitality/checks') => 'past_checks',
            str_starts_with($path, 'hospitality/folios') => 'folio_pay',
            str_starts_with($path, 'hospitality/reports'),
            str_starts_with($path, 'reports/hospitality-') => 'reports',
            str_starts_with($path, 'stock-take-sessions'),
            str_starts_with($path, 'stock-take-lines'),
            str_starts_with($path, 'inventory/stock-take') => 'stock_count',
            str_starts_with($path, 'inventory/receive'),
            str_starts_with($path, 'stock-receipts') => 'receiving',
            str_starts_with($path, 'inventory/adjust'),
            $path === 'current-stock',
            str_starts_with($path, 'current-stock'),
            str_starts_with($path, 'inventory/availability') => 'kitchen_stock',
            str_starts_with($path, 'products'),
            str_starts_with($path, 'categories'),
            str_starts_with($path, 'sub-categories'),
            str_starts_with($path, 'uoms'),
            str_starts_with($path, 'vats'),
            str_starts_with($path, 'retail-package') => 'menu_items',
            str_starts_with($path, 'suppliers'),
            str_starts_with($path, 'supplier-payments'),
            str_starts_with($path, 'reference/suppliers') => 'vendors',
            str_starts_with($path, 'expenses'),
            str_starts_with($path, 'expense-groups') => 'expenses',
            str_starts_with($path, 'customers') => 'guests',
            $path === 'sales',
            str_starts_with($path, 'sales/orders'),
            str_starts_with($path, 'sales/carts') => 'orders',
            str_starts_with($path, 'reports/') => 'reports',
            default => null,
        };
    }

    public function homeModuleAccessibleFromCapabilities(array $mobileAppCapabilities, string $path): ?bool
    {
        $key = $this->homeModuleKeyForPath($path);
        if ($key === null) {
            return null;
        }

        $module = $mobileAppCapabilities['home_modules'][$key] ?? null;
        if (! is_array($module)) {
            return null;
        }

        return (bool) ($module['accessible'] ?? false);
    }

    /**
     * True when the user may operate CentrixAsMobile APIs (path middleware + module gates).
     * Mirrors UserLoginChannelService::assertCanLogin soft-allow so hotel backoffice staff
     * who can sign in are not 403'd on every page afterward.
     */
    protected function userHasCentrixAsMobileAccess(User $user, CapabilityGate $gate): bool
    {
        try {
            $channels = app(UserLoginChannelService::class);

            if ($this->currentTokenChannel() === UserLoginChannelService::CENTRIX_AS_MOBILE) {
                return true;
            }

            $allowed = $channels->channelsFor($user);
            if (in_array(UserLoginChannelService::CENTRIX_AS_MOBILE, $allowed, true)) {
                return true;
            }

            // Legacy Field Mobile checkbox still unlocks AS (same as login remapping).
            if (in_array(UserLoginChannelService::MOBILE, $allowed, true)
                && in_array(UserLoginChannelService::CENTRIX_AS_MOBILE, $gate->allowedLoginChannels(), true)
            ) {
                return true;
            }

            // Hotel / shop soft-allow: org enables CentrixAsMobile + admin or web/POS channel.
            if (! in_array(UserLoginChannelService::CENTRIX_AS_MOBILE, $gate->allowedLoginChannels(), true)) {
                return false;
            }

            return (bool) $user->is_admin
                || array_intersect($allowed, [
                    UserLoginChannelService::BACKOFFICE,
                    UserLoginChannelService::POS,
                ]) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    protected function currentTokenChannel(): ?string
    {
        try {
            $token = SanctumTokenResolver::fromRequest(request());
            $channel = $token?->login_channel;

            return is_string($channel) && $channel !== '' ? strtolower($channel) : null;
        } catch (\Throwable) {
            return null;
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
