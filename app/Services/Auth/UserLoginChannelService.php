<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Mobile\ManagerAppModuleAccessService;
use App\Services\Mobile\MobileAppModuleAccessService;
use Illuminate\Validation\ValidationException;

class UserLoginChannelService
{
    public const BACKOFFICE = 'backoffice';

    public const POS = 'pos';

    public const MOBILE = 'mobile';

    public const MANAGER = 'manager';

    /** @var list<string> */
    public const ALL = [
        self::BACKOFFICE,
        self::POS,
        self::MOBILE,
        self::MANAGER,
    ];

    /** @return list<string> */
    public function defaultChannels(): array
    {
        return self::ALL;
    }

    /** @param  list<string>|null  $channels */
    public function normalize(?array $channels): array
    {
        if ($channels === null || $channels === []) {
            return $this->defaultChannels();
        }

        $valid = array_values(array_unique(array_intersect($channels, self::ALL)));

        return $valid !== [] ? $valid : $this->defaultChannels();
    }

    /** @return list<string> */
    public function channelsFor(User $user): array
    {
        $stored = $user->login_channels;
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            $stored = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($stored) || $stored === []) {
            if ($user->is_mobile_user) {
                return [self::MOBILE];
            }

            return $this->defaultChannels();
        }

        return $this->normalize($stored);
    }

    public function assertCanLogin(User $user, string $channel): void
    {
        $channel = $this->normalizeChannel($channel);
        $allowed = $this->channelsFor($user);

        if (in_array($channel, $allowed, true)) {
            return;
        }

        // Web users open External POS from the module switcher. Terminal permission
        // and the organisation module are checked when the session is switched.
        if ($channel === self::POS && in_array(self::BACKOFFICE, $allowed, true)) {
            return;
        }

        // Unified web login issues a backoffice token; POS-only accounts use the same sign-in page.
        if ($channel === self::BACKOFFICE) {
            $webEligible = array_intersect($allowed, [self::BACKOFFICE, self::POS]);
            if ($webEligible !== []) {
                return;
            }
        }

        // Platform super-admins may use Centrix Manager even if login_channels was seeded as backoffice-only.
        if ($channel === self::MANAGER && $this->isPlatformSuperAdmin($user)) {
            return;
        }

        throw ValidationException::withMessages([
            'login_channel' => [
                sprintf('This account is not allowed to sign in via %s.', $this->label($channel)),
            ],
        ]);
    }

    public function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));

        if (! in_array($channel, self::ALL, true)) {
            throw ValidationException::withMessages([
                'login_channel' => ['Invalid login channel.'],
            ]);
        }

        return $channel;
    }

    public function tokenCanAccessPath(string $tokenChannel, string $path): bool
    {
        $tokenChannel = $this->normalizeChannel($tokenChannel);
        $path = $this->normalizeApiPath($path);

        if ($tokenChannel === self::BACKOFFICE) {
            return true;
        }

        if ($tokenChannel === self::POS) {
            return $this->isPosAllowedPath($path);
        }

        if ($tokenChannel === self::MANAGER) {
            return $this->isManagerAllowedPath($path);
        }

        return $this->isMobileAllowedPath($path);
    }

    public function mobileTokenCanAccessPath(User $user, string $path): bool
    {
        $path = $this->normalizeApiPath($path);

        if ($this->isSharedAuthPath($path)) {
            return true;
        }

        if (! $this->isMobileAllowedPath($path)) {
            return false;
        }

        $modules = $this->mobileModulesForUser($user);
        if ($modules === null) {
            return false;
        }
        $salesOk = (bool) ($modules['modules']['sales']['accessible'] ?? false);
        $driverOk = (bool) ($modules['modules']['driver']['accessible'] ?? false);

        if (str_starts_with($path, 'mobile/driver/')) {
            return $driverOk;
        }

        if (str_starts_with($path, 'mobile/')) {
            return $salesOk;
        }

        $salesOnlyPrefixes = [
            'sales/carts',
            'sales/customers',
            'sales/loyalty-cards',
        ];
        foreach ($salesOnlyPrefixes as $prefix) {
            if ($this->pathMatchesAny($path, [$prefix])) {
                return $salesOk;
            }
        }

        if ($this->pathMatchesAny($path, ['sales/']) && str_contains($path, '/payments')) {
            return $salesOk || $driverOk;
        }

        return $salesOk || $driverOk;
    }

    public function managerTokenCanAccessPath(User $user, string $path): bool
    {
        $path = $this->normalizeApiPath($path);

        if ($this->isSharedAuthPath($path)) {
            return true;
        }

        // Platform operators manage tenants via admin/*; web already gates with erp.super_admin.
        if (str_starts_with($path, 'admin/') && $this->isPlatformSuperAdmin($user)) {
            return true;
        }

        if ($this->isManagerDeniedPath($path)) {
            return false;
        }

        $module = $this->managerModuleForUser($user);

        return (bool) ($module['accessible'] ?? false);
    }

    /** @return array<string, mixed>|null */
    protected function mobileModulesForUser(User $user): ?array
    {
        $attrKey = 'login_channel.mobile_modules.'.$user->id;
        try {
            $request = request();
            if ($request->attributes->has($attrKey)) {
                return $request->attributes->get($attrKey);
            }
        } catch (\Throwable) {
            $request = null;
        }

        $organization = $user->organization ?? Organization::query()->find($user->organization_id);
        if (! $organization) {
            return null;
        }

        $gate = app(ErpContext::class)->gateForOrganization($organization);
        $modules = app(MobileAppModuleAccessService::class)->capabilitiesForUser($user, $gate);
        $request?->attributes->set($attrKey, $modules);

        return $modules;
    }

    /** @return array<string, mixed> */
    protected function managerModuleForUser(User $user): array
    {
        $attrKey = 'login_channel.manager_module.'.$user->id;
        try {
            $request = request();
            if ($request->attributes->has($attrKey)) {
                return $request->attributes->get($attrKey);
            }
        } catch (\Throwable) {
            $request = null;
        }

        $organization = $user->organization ?? Organization::query()->find($user->organization_id);
        if (! $organization) {
            return ['accessible' => false];
        }

        $gate = app(ErpContext::class)->gateForOrganization($organization);
        $module = app(ManagerAppModuleAccessService::class)->capabilitiesForUser($user, $gate);
        $request?->attributes->set($attrKey, $module);

        return $module;
    }

    public function isPlatformSuperAdmin(User $user): bool
    {
        if (! $user->is_super_admin) {
            return false;
        }

        $organization = $user->organization ?? Organization::query()->find($user->organization_id);
        if (! $organization) {
            return false;
        }

        return app(CapabilityGate::class)->forOrganization($organization)->isPlatformOrganization();
    }

    /** @param  list<string>  $channels */
    public function syncLegacyMobileFlag(array $channels): bool
    {
        return in_array(self::MOBILE, $channels, true);
    }

    public function label(string $channel): string
    {
        return match ($channel) {
            self::BACKOFFICE => 'CentrixERP',
            self::POS => 'POS',
            self::MOBILE => 'CentrixMobileapp',
            self::MANAGER => 'Centrix Manager',
            default => $channel,
        };
    }

    protected function normalizeApiPath(string $path): string
    {
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'api/v1/')) {
            return substr($path, 7);
        }

        return $path;
    }

    protected function isPosAllowedPath(string $path): bool
    {
        if ($this->isSharedAuthPath($path)) {
            return true;
        }

        // Receipt / Z-report cashier name — show only, never the users index.
        if (preg_match('#^users/[^/]+$#', $path) === 1) {
            return true;
        }

        $prefixes = [
            'pos/',
            'sales',
            'sales/carts',
            'sales/orders',
            'sales/loyalty-cards',
            'tills',
            'till-float-sessions',
            'branches',
            'routes',
            'products',
            'customers',
            'payment-methods',
            'vouchers',
            'loyalty-cards',
            'inventory/availability',
            'current-stock',
            // Legacy CRUD paths + permission-light pickers used by POS / inventory forms.
            'uoms',
            'vats',
            'reference/',
            'retail-package-settings',
            'payments/',
            'notifications',
            'action-requests/',
            'system-issue-reports',
            'kra-responses',
            'legacy-orders',
            'erp/organization',
            'erp/settings',
            'organizations',
        ];

        return $this->pathMatchesAny($path, $prefixes);
    }

    protected function isMobileAllowedPath(string $path): bool
    {
        if ($this->isSharedAuthPath($path)) {
            return true;
        }

        if ($this->isMobileAllowedSalesReportPath($path)) {
            return true;
        }

        $prefixes = [
            'mobile/',
            'sales/carts',
            'sales/customers',
            'sales/orders',
            'sales/loyalty-cards',
            // AS / shop POS float sessions (declare opening float).
            'pos/',
            'tills',
            'till-float-sessions',
            'branches',
            'customers',
            'products',
            'payment-methods',
            'loyalty-cards',
            'inventory/availability',
            // AS shop purchases / stock reconciliation.
            'inventory/receive',
            'inventory/adjust',
            'stock-receipts',
            'current-stock',
            'routes',
            'uoms',
            'vats',
            'suppliers',
            'reference/',
            'retail-package-settings',
            'payments/',
            'notifications',
            'action-requests/',
            'system-issue-reports',
            'erp/settings',
            // AS shop expenses (fallback when no open till session).
            'expenses',
            'expense-groups',
        ];

        return $this->pathMatchesAny($path, $prefixes);
    }

    /**
     * Narrow sales analytics for AS / sales mobile — not the full manager reports suite.
     */
    protected function isMobileAllowedSalesReportPath(string $path): bool
    {
        return in_array($path, [
            'reports/sales-by-supplier',
            'reports/sales-by-product',
            'reports/sales-by-user',
            'reports/sales-by-customer',
            'reports/category-sales',
            'reports/daily-sales',
        ], true);
    }

    protected function isManagerAllowedPath(string $path): bool
    {
        if ($this->isSharedAuthPath($path)) {
            return true;
        }

        $prefixes = [
            'manager/',
            'notifications',
            'action-requests/',
            'reports/',
            'background-tasks/',
            'users',
            'roles',
            'branches',
            'auth/',
            'dispatch-trips',
            'customers',
            'products',
            'catalogue/',
            'current-stock',
            'routes',
            'drivers',
            'vehicles',
            'sales/',
            'payments/',
        ];

        return $this->pathMatchesAny($path, $prefixes);
    }

    protected function isManagerDeniedPath(string $path): bool
    {
        return str_starts_with($path, 'admin/');
    }

    protected function isSharedAuthPath(string $path): bool
    {
        // Capabilities bootstrap + cheap version poll (all login channels).
        return str_starts_with($path, 'auth/')
            || $path === 'erp/capabilities'
            || str_starts_with($path, 'erp/capabilities/')
            || $path === 'health';
    }

    /** @param  list<string>  $prefixes */
    protected function pathMatchesAny(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === rtrim($prefix, '/')) {
                return true;
            }
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
