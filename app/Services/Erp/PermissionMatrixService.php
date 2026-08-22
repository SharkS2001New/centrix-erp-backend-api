<?php

namespace App\Services\Erp;

use App\Models\Permission;

class PermissionMatrixService
{
    /** @return array<string, string> module key => display label */
    public static function modules(): array
    {
        $modules = [];
        foreach (config('permission_registry.groups', []) as $key => $group) {
            $modules[$key] = $group['label'];
        }

        return $modules;
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return ['view', 'create', 'edit', 'delete', 'approve', 'reset', 'give', 'deliver'];
    }

    /** @return list<string> */
    public static function allRegistryCodes(): array
    {
        $codes = [];
        foreach (config('permission_registry.groups', []) as $moduleKey => $group) {
            foreach ($group['features'] as $featureKey => $feature) {
                foreach ($feature['actions'] as $action) {
                    $codes[] = "{$moduleKey}.{$featureKey}.{$action}";
                }
            }
        }

        return $codes;
    }

    /** Prevents ensure() ↔ industry-catalog helpers from re-entering each other. */
    protected static bool $ensuring = false;

    public static function ensure(): void
    {
        if (self::$ensuring) {
            return;
        }

        self::$ensuring = true;
        try {
            self::ensureRegistryPermissions();
            self::ensureCapabilityCodes();
            self::remapLegacyPermissionAssignments();
            self::ensureDiscountGiveForAdminRoles();
            self::ensureSalesOrderApproveForAdminRoles();
            self::ensureDiscountApprovalsForAdminRoles();
            self::ensureLpoApproveForAdminRoles();
            self::ensureStockTakeResetForAdminRoles();
            self::ensureNotificationsForBackofficeRoles();
            self::ensureShopDebtorsForAdminRoles();
            self::migrateLegacyShopDebtorsPermissions();
            self::ensureCollectPaymentForPosCashierRoles();
            self::ensureHrTimeAttendancePagesForExistingRoles();
            // Shared Administrator must keep every industry shell (commerce + hospitality).
            // Without this, hotel tenants only see Administration after the is_admin
            // least-privilege change (operational access comes from the role matrix).
            self::ensureAdministratorIndustryCatalogPermissions();
        } finally {
            self::$ensuring = false;
        }
    }

    /**
     * Grant every industry-catalog permission to Administrator / Admin roles.
     * Safe to call repeatedly (insertOrIgnore).
     *
     * @return int Number of role_permission rows inserted (0 when already complete).
     */
    public static function ensureAdministratorIndustryCatalogPermissions(): int
    {
        $permissionIds = self::allIndustryPermissionIds();
        if ($permissionIds === []) {
            return 0;
        }

        $roleIds = \App\Models\Role::query()
            ->whereIn('role_name', ['Administrator', 'Admin'])
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return 0;
        }

        $inserted = 0;
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $ok = \Illuminate\Support\Facades\DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
                if ($ok) {
                    $inserted++;
                }
            }
        }

        return $inserted;
    }

    public static function ensureRegistryPermissions(): void
    {
        foreach (config('permission_registry.groups', []) as $moduleKey => $group) {
            foreach ($group['features'] as $featureKey => $feature) {
                foreach ($feature['actions'] as $action) {
                    $code = "{$moduleKey}.{$featureKey}.{$action}";
                    $name = "{$group['label']} / {$feature['label']} — ".ucfirst($action);
                    Permission::firstOrCreate(
                        ['permission_code' => $code],
                        ['permission_name' => $name, 'module' => $moduleKey]
                    );
                }
            }
        }
    }

    /** Route capability codes used by erp.permission middleware. */
    public static function ensureCapabilityCodes(): void
    {
        $moduleByCode = [
            'sales.create' => 'sales',
            'sales.manage' => 'sales',
            'sales.view' => 'sales',
            'mobile.access' => 'mobile_sales',
            'manager.access' => 'mobile_manager',
            'driver.mobile' => 'mobile_driver',
            'payments.manage' => 'payments',
            'payments.view' => 'payments',
            'inventory.view' => 'inventory',
            'inventory.manage' => 'inventory',
            'catalogue.view' => 'catalogue',
            'reports.view' => 'reports',
            'reports.builder' => 'reports',
            'ai.assist' => 'ai',
            'purchasing.view' => 'purchasing',
            'purchasing.manage' => 'purchasing',
            'customers.view' => 'customers',
            'customers.manage' => 'customers',
            'fulfillment.view' => 'fulfillment',
            'fulfillment.manage' => 'fulfillment',
            'accounting.view' => 'accounting',
            'accounting.manage' => 'accounting',
            'hr.view' => 'hr',
            'hr.manage' => 'hr',
            'admin.view' => 'admin',
            'admin.manage' => 'admin',
            'pos.till' => 'pos',
            'products.manage' => 'catalogue',
        ];

        foreach (config('permissions', []) as $code => $description) {
            if (! is_string($description)) {
                continue;
            }

            $module = $moduleByCode[$code] ?? explode('.', $code)[0];
            Permission::firstOrCreate(
                ['permission_code' => $code],
                [
                    'permission_name' => $description,
                    'module' => $module,
                ]
            );
        }
    }

    /** @return array<string, list<string>> */
    public static function erpModuleMap(): array
    {
        return config('permission_module_map', []);
    }

    /**
     * @param  bool  $includeAdminWhenDisabled  When true (platform admin acting as tenant), keep
     *                                          Administration permissions visible even if the tenant
     *                                          has the Administration workspace disabled.
     */
    public static function isRegistryModuleEnabled(
        string $registryModule,
        CapabilityGate $gate,
        bool $includeAdminWhenDisabled = false,
    ): bool {
        if ($includeAdminWhenDisabled && $registryModule === 'admin') {
            return true;
        }
        if ($registryModule === 'ai') {
            return $gate->aiPlatformEnabled();
        }
        if ($registryModule === 'mobile_sales') {
            return $gate->mobileSalesEnabled();
        }
        if ($registryModule === 'mobile_driver') {
            return $gate->driverMobileEnabled();
        }
        if ($registryModule === 'mobile_manager') {
            return $gate->managerAppEnabled();
        }

        $erpKeys = self::erpModuleMap()[$registryModule] ?? [$registryModule];

        foreach ($erpKeys as $key) {
            if ($gate->enabled((string) $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delivery routes are used by Field Sales (mobile/backoffice) without the full
     * Distribution logistics module. Keep route CRUD available whenever sales or
     * customers modules are on.
     */
    public static function routesCatalogEnabled(CapabilityGate $gate): bool
    {
        foreach (['distribution', 'sales.mobile', 'sales.backend', 'customers_suppliers'] as $key) {
            if ($gate->enabled($key)) {
                return true;
            }
        }

        return false;
    }

    public static function isRoutePermissionCode(string $permissionCode): bool
    {
        return str_starts_with($permissionCode, 'fulfillment.routes.');
    }

    public static function permissionModuleEnabled(
        string $permissionCode,
        string $registryModule,
        CapabilityGate $gate,
        bool $includeAdminWhenDisabled = false,
    ): bool {
        if (self::isRoutePermissionCode($permissionCode)) {
            return self::routesCatalogEnabled($gate);
        }

        return self::isRegistryModuleEnabled($registryModule, $gate, $includeAdminWhenDisabled);
    }

    /** @return list<int> Permission ids whose registry module is enabled for the org. */
    public static function enabledPermissionIds(CapabilityGate $gate, bool $includeAdminWhenDisabled = false): array
    {
        self::ensure();

        return Permission::query()
            ->get()
            ->filter(fn (Permission $permission) => self::permissionModuleEnabled(
                (string) $permission->permission_code,
                (string) $permission->module,
                $gate,
                $includeAdminWhenDisabled,
            ))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Permission ids allowed for an industry (application allow-list) and currently enabled modules.
     *
     * @return list<int>
     */
    public static function industryEnabledPermissionIds(
        string $industry,
        CapabilityGate $gate,
        bool $includeAdminWhenDisabled = false,
    ): array {
        $enabled = array_flip(self::enabledPermissionIds($gate, $includeAdminWhenDisabled));
        $industryModules = array_flip(IndustryRegistry::registryModulesForIndustry($industry));

        if ($industryModules === []) {
            return array_map('intval', array_keys($enabled));
        }

        return Permission::query()
            ->get()
            ->filter(function (Permission $permission) use ($enabled, $industryModules) {
                if (! isset($enabled[(int) $permission->id])) {
                    return false;
                }

                $module = (string) $permission->module;

                return isset($industryModules[$module]);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * All permission ids that belong to any industry application shell (for shared Administrator).
     *
     * @return list<int>
     */
    public static function allIndustryPermissionIds(): array
    {
        // Do not call ensure() here — this helper is invoked from ensure().
        // Callers outside ensure() should run PermissionMatrixService::ensure() first.
        $codes = array_flip(IndustryRegistry::permissionCodesForAllIndustries());

        return Permission::query()
            ->get()
            ->filter(fn (Permission $permission) => isset($codes[(string) $permission->permission_code]))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Permission ids for one industry's application shells (ignores org module enablement).
     *
     * @return list<int>
     */
    public static function permissionIdsForIndustry(string $industry): array
    {
        // Do not call ensure() here — may be invoked while ensure() is already running.
        $codes = array_flip(IndustryRegistry::permissionCodesForIndustry($industry));

        return Permission::query()
            ->get()
            ->filter(fn (Permission $permission) => isset($codes[(string) $permission->permission_code]))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public static function groupedForUi(
        ?CapabilityGate $gate = null,
        bool $includeAdminWhenDisabled = false,
        ?string $industry = null,
    ): array {
        self::ensure();

        $byCode = Permission::query()->get()->keyBy('permission_code');
        $groups = [];
        $industryModules = $industry !== null && $industry !== ''
            ? array_flip(IndustryRegistry::registryModulesForIndustry($industry))
            : null;

        foreach (config('permission_registry.groups', []) as $moduleKey => $groupDef) {
            if (is_array($industryModules) && $industryModules !== [] && ! isset($industryModules[$moduleKey])) {
                continue;
            }
            if ($gate !== null && ! self::isRegistryModuleEnabled($moduleKey, $gate, $includeAdminWhenDisabled)) {
                continue;
            }
            $features = [];
            foreach ($groupDef['features'] as $featureKey => $featureDef) {
                $permissions = [];
                foreach ($featureDef['actions'] as $action) {
                    $code = "{$moduleKey}.{$featureKey}.{$action}";
                    $perm = $byCode->get($code);
                    if (! $perm) {
                        continue;
                    }
                    $permissions[] = [
                        'id' => (int) $perm->id,
                        'permission_code' => $code,
                        'permission_name' => $perm->permission_name,
                        'action' => $action,
                    ];
                }
                if ($permissions === []) {
                    continue;
                }
                $features[] = [
                    'key' => $featureKey,
                    'label' => $featureDef['label'],
                    'permissions' => $permissions,
                ];
            }

            if ($features === []) {
                continue;
            }

            $groups[] = [
                'module' => $moduleKey,
                'label' => $groupDef['label'],
                'features' => $features,
            ];
        }

        return $groups;
    }

    /** @return list<array<string, mixed>> */
    public static function applicationsGroupedForUi(
        ?CapabilityGate $gate = null,
        bool $includeAdminWhenDisabled = false,
        ?string $industry = null,
    ): array {
        $groupsByModule = collect(self::groupedForUi($gate, $includeAdminWhenDisabled, $industry))->keyBy('module');
        $applications = [];
        $industryAppIds = $industry !== null && $industry !== ''
            ? array_flip(IndustryRegistry::permissionApplicationIdsForIndustry($industry))
            : null;

        foreach (config('permission_applications.order', []) as $appId) {
            $def = config("permission_applications.applications.{$appId}");
            if (! is_array($def)) {
                continue;
            }

            if (is_array($industryAppIds) && $industryAppIds !== [] && ! isset($industryAppIds[$appId])) {
                continue;
            }

            // Hotel Backoffice shares catalogue/inventory modules with retail Backoffice.
            // Only expose it when the hospitality registry module itself is enabled.
            if ($appId === 'hospitality_backoffice' && ! $groupsByModule->has('hospitality')) {
                continue;
            }

            // Hotel POS only when its terminal module is enabled.
            if ($appId === 'hotel_bar_pos' && ! $groupsByModule->has('hotel_bar_pos')) {
                continue;
            }

            // Distribution shares the reports registry with other apps; only show it when
            // the fulfillment (Distribution) module is enabled for the organization.
            if ($appId === 'distribution' && ! $groupsByModule->has('fulfillment')) {
                continue;
            }

            $modules = [];
            foreach ($def['registry_modules'] ?? [] as $registryModule) {
                $group = $groupsByModule->get($registryModule);
                // Manager roles must be able to grant report permissions even when the org
                // has no *.reports ERP children enabled yet (shared reports registry off).
                if (! is_array($group) && $appId === 'manager' && $registryModule === 'reports') {
                    $group = self::buildRegistryModuleGroup('reports');
                }
                if (! is_array($group)) {
                    continue;
                }

                $filtered = self::filterApplicationModuleGroup($group, $def, (string) $registryModule);
                if ($filtered !== null) {
                    $modules[] = $filtered;
                }
            }

            if ($modules === []) {
                continue;
            }

            $applications[] = [
                'id' => (string) $appId,
                'label' => (string) ($def['label'] ?? $appId),
                'description' => $def['description'] ?? null,
                'standalone' => (bool) ($def['standalone'] ?? false),
                'modules' => $modules,
            ];
        }

        return $applications;
    }

    /**
     * Build one registry module group for the Roles UI without org-module gating.
     * Used when Manager must expose report permissions independently of *.reports flags.
     *
     * @return array<string, mixed>|null
     */
    public static function buildRegistryModuleGroup(string $moduleKey): ?array
    {
        self::ensure();

        $groupDef = config("permission_registry.groups.{$moduleKey}");
        if (! is_array($groupDef)) {
            return null;
        }

        $byCode = Permission::query()->get()->keyBy('permission_code');
        $features = [];
        foreach ($groupDef['features'] ?? [] as $featureKey => $featureDef) {
            if (! is_array($featureDef)) {
                continue;
            }
            $permissions = [];
            foreach ($featureDef['actions'] ?? [] as $action) {
                $code = "{$moduleKey}.{$featureKey}.{$action}";
                $perm = $byCode->get($code);
                if (! $perm) {
                    continue;
                }
                $permissions[] = [
                    'id' => (int) $perm->id,
                    'permission_code' => $code,
                    'permission_name' => $perm->permission_name,
                    'action' => $action,
                ];
            }
            if ($permissions === []) {
                continue;
            }
            $features[] = [
                'key' => $featureKey,
                'label' => $featureDef['label'] ?? $featureKey,
                'permissions' => $permissions,
            ];
        }

        if ($features === []) {
            return null;
        }

        return [
            'module' => $moduleKey,
            'label' => $groupDef['label'] ?? $moduleKey,
            'features' => $features,
        ];
    }

    /**
     * Optionally keep only selected features for a registry module within an application,
     * and override the module label (e.g. pos till ops under Backoffice).
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $appDef
     * @return array<string, mixed>|null
     */
    protected static function filterApplicationModuleGroup(array $group, array $appDef, string $registryModule): ?array
    {
        $featureAllowList = $appDef['module_features'][$registryModule] ?? null;
        $labelOverride = $appDef['module_labels'][$registryModule] ?? null;

        if (is_array($featureAllowList)) {
            $allowed = array_flip(array_map('strval', $featureAllowList));
            $features = array_values(array_filter(
                $group['features'] ?? [],
                static fn (array $feature): bool => isset($allowed[(string) ($feature['key'] ?? '')]),
            ));
            if ($features === []) {
                return null;
            }
            $group = [...$group, 'features' => $features];
        }

        if (is_string($labelOverride) && $labelOverride !== '') {
            $group['label'] = $labelOverride;
        }

        return $group;
    }

    /** Remap role assignments from retired mobile.* codes to mobile_sales.* / mobile_driver.* */
    public static function remapLegacyPermissionAssignments(): void
    {
        $map = [
            'mobile.dashboard.view' => 'mobile_sales.dashboard.view',
            'mobile.orders.view' => 'mobile_sales.orders.view',
            'mobile.orders.create' => 'mobile_sales.orders.create',
            'mobile.orders.edit' => 'mobile_sales.orders.edit',
            'mobile.customers.view' => 'mobile_sales.customers.view',
            'mobile.customers.create' => 'mobile_sales.customers.create',
            'mobile.customers.edit' => 'mobile_sales.customers.edit',
            'mobile.catalog.view' => 'mobile_sales.catalog.view',
            'mobile.stock.view' => 'mobile_sales.stock.view',
            'mobile.routes.view' => 'mobile_sales.routes.view',
            'mobile.drivers.view' => 'mobile_driver.deliveries.view',
            'mobile.drivers.deliver' => 'mobile_driver.deliveries.deliver',
            'catalogue.vat_rates.view' => 'pricing_tax.vat_rates.view',
            'catalogue.vat_rates.create' => 'pricing_tax.vat_rates.create',
            'catalogue.vat_rates.edit' => 'pricing_tax.vat_rates.edit',
            'catalogue.vat_rates.delete' => 'pricing_tax.vat_rates.delete',
            'catalogue.price_history.view' => 'pricing_tax.price_history.view',
            'catalogue.kra_invoices.view' => 'pricing_tax.kra_invoices.view',
        ];

        $permissions = Permission::query()
            ->whereIn('permission_code', array_merge(array_keys($map), array_values($map)))
            ->pluck('id', 'permission_code');

        foreach ($map as $from => $to) {
            $fromId = $permissions[$from] ?? null;
            $toId = $permissions[$to] ?? null;
            if (! $fromId || ! $toId) {
                continue;
            }

            $roleIds = \Illuminate\Support\Facades\DB::table('role_permissions')
                ->where('permission_id', $fromId)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                \Illuminate\Support\Facades\DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $toId,
                ]);
            }

            \Illuminate\Support\Facades\DB::table('role_permissions')
                ->where('permission_id', $fromId)
                ->delete();

            \Illuminate\Support\Facades\DB::table('user_permission_overrides')
                ->where('permission_id', $fromId)
                ->update(['permission_id' => $toId]);
        }
    }

    /** Org administrators should always be able to give discounts directly. */
    public static function ensureDiscountGiveForAdminRoles(): void
    {
        self::ensureCodesForAdminRoles(['sales.discounts.give']);
    }

    /** Org administrators should be able to approve discount requests. */
    public static function ensureSalesOrderApproveForAdminRoles(): void
    {
        self::ensureCodesForAdminRoles(['sales.orders.approve']);
    }

    /** Org administrators should be able to approve discount requests. */
    public static function ensureDiscountApprovalsForAdminRoles(): void
    {
        self::ensureCodesForAdminRoles(['admin.discount_approvals.approve']);
    }

    /** Org administrators should be able to approve LPO requests. */
    public static function ensureLpoApproveForAdminRoles(): void
    {
        self::ensureCodesForAdminRoles(['purchasing.lpo.approve']);
    }

    /** Administrator roles should be able to zero stocks before a stock take count. */
    public static function ensureStockTakeResetForAdminRoles(): void
    {
        self::ensureCodesForAdminRoles(['inventory.stock_take.reset']);
    }

    /**
     * Attach industry-catalog codes to Administrator roles only (skip orphans).
     *
     * @param  list<string>  $codes
     */
    protected static function ensureCodesForAdminRoles(array $codes): void
    {
        $industryCodes = array_flip(IndustryRegistry::permissionCodesForAllIndustries());
        $roleIds = \App\Models\Role::query()
            ->whereIn('role_name', ['Administrator', 'Admin'])
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        foreach ($codes as $code) {
            if (! isset($industryCodes[$code])) {
                continue;
            }
            $permissionId = Permission::query()
                ->where('permission_code', $code)
                ->value('id');
            if (! $permissionId) {
                continue;
            }
            foreach ($roleIds as $roleId) {
                \Illuminate\Support\Facades\DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    /**
     * Notifications were previously ungated; grant view to any role that already
     * has business-summary access so existing backoffice users keep the link.
     */
    public static function ensureNotificationsForBackofficeRoles(): void
    {
        $notificationId = Permission::query()
            ->where('permission_code', 'admin.notifications.view')
            ->value('id');
        $overviewId = Permission::query()
            ->where('permission_code', 'dashboard.overview.view')
            ->value('id');

        if (! $notificationId || ! $overviewId) {
            return;
        }

        $roleIds = \Illuminate\Support\Facades\DB::table('role_permissions')
            ->where('permission_id', $overviewId)
            ->pluck('role_id');

        $adminRoleIds = \App\Models\Role::query()
            ->whereIn('role_name', ['Administrator', 'Admin'])
            ->pluck('id');

        foreach ($roleIds->merge($adminRoleIds)->unique() as $roleId) {
            \Illuminate\Support\Facades\DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $notificationId,
            ]);
        }
    }

    /** Grant shop-debtor queue permissions to Administrator / Admin roles. */
    public static function ensureShopDebtorsForAdminRoles(): void
    {
        $shopDebtorsIds = Permission::query()
            ->whereIn('permission_code', \App\Support\ShopDebtorsPermissions::allViewPermissionCodes())
            ->pluck('id');

        if ($shopDebtorsIds->isEmpty()) {
            return;
        }

        $adminRoleIds = \App\Models\Role::query()
            ->whereIn('role_name', ['Administrator', 'Admin'])
            ->pluck('id');

        foreach ($adminRoleIds as $roleId) {
            foreach ($shopDebtorsIds as $permissionId) {
                \Illuminate\Support\Facades\DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    /** Copy legacy customers.* shop-debtors grants onto shop_debtors.* queue permissions. */
    public static function migrateLegacyShopDebtorsPermissions(): void
    {
        $legacyToNew = [
            'customers.shop_debtors.view' => \App\Support\ShopDebtorsPermissions::allViewPermissionCodes(),
            'customers.shop_debtors_unpaid.view' => [\App\Support\ShopDebtorsPermissions::permissionCodeForBucket('unpaid')],
            'customers.shop_debtors_partial.view' => [\App\Support\ShopDebtorsPermissions::permissionCodeForBucket('partial')],
            'customers.shop_debtors_paid.view' => [\App\Support\ShopDebtorsPermissions::permissionCodeForBucket('paid')],
        ];

        foreach ($legacyToNew as $legacyCode => $newCodes) {
            $legacyId = Permission::query()->where('permission_code', $legacyCode)->value('id');
            if (! $legacyId) {
                continue;
            }

            $newIds = Permission::query()->whereIn('permission_code', $newCodes)->pluck('id');
            if ($newIds->isEmpty()) {
                continue;
            }

            $roleIds = \Illuminate\Support\Facades\DB::table('role_permissions')
                ->where('permission_id', $legacyId)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                foreach ($newIds as $permissionId) {
                    \Illuminate\Support\Facades\DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    /**
     * Backoffice Collect payment (complete remaining / partial balances) for POS cashier roles.
     */
    public static function ensureCollectPaymentForPosCashierRoles(): void
    {
        $collectId = Permission::query()
            ->where('permission_code', 'sales.collect_payment.create')
            ->value('id');
        $checkoutId = Permission::query()
            ->where('permission_code', 'pos.checkout.create')
            ->value('id');

        if (! $collectId || ! $checkoutId) {
            return;
        }

        $roleIds = \Illuminate\Support\Facades\DB::table('role_permissions')
            ->where('permission_id', $checkoutId)
            ->pluck('role_id');

        $namedCashierIds = \App\Models\Role::query()
            ->whereIn('role_name', ['Cashier'])
            ->pluck('id');

        foreach ($roleIds->merge($namedCashierIds)->unique() as $roleId) {
            \Illuminate\Support\Facades\DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $collectId,
            ]);
        }
    }

    /**
     * Grant additional time-attendance navigation pages to roles that already
     * have core attendance or HR view permissions. Safe to call repeatedly.
     *
     * @return int Number of role_permission rows inserted (0 when already complete).
     */
    public static function ensureHrTimeAttendancePagesForExistingRoles(): int
    {
        self::ensureRegistryPermissions();

        $map = [
            'hr.attendance.view' => [
                'hr.attendance_history.view',
                'hr.missed_punches.view',
                'hr.duplicate_punches.view',
                'hr.absents.view',
                'hr.lateness.view',
            ],
            'hr.view' => [
                'hr.absents.view',
                'hr.missed_punches.view',
                'hr.duplicate_punches.view',
                'hr.attendance_history.view',
                'hr.lateness.view',
                'hr.pending_overtime.view',
                'hr.shifts.view',
            ],
        ];

        // Collect all permission codes we need to resolve to IDs.
        $codes = [];
        foreach ($map as $trigger => $adds) {
            $codes[] = $trigger;
            foreach ($adds as $c) {
                $codes[] = $c;
            }
        }

        $permissions = Permission::query()
            ->whereIn('permission_code', array_values(array_unique($codes)))
            ->pluck('id', 'permission_code');

        $inserted = 0;

        foreach ($map as $trigger => $adds) {
            $triggerId = $permissions[$trigger] ?? null;
            if (! $triggerId) {
                continue;
            }

            $roleIds = \Illuminate\Support\Facades\DB::table('role_permissions')
                ->where('permission_id', $triggerId)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                foreach ($adds as $code) {
                    $permissionId = $permissions[$code] ?? null;
                    if (! $permissionId) {
                        continue;
                    }

                    $ok = \Illuminate\Support\Facades\DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                    if ($ok) {
                        $inserted++;
                    }
                }
            }
        }

        return $inserted;
    }
}
