<?php

namespace App\Services\Erp;

use App\Models\Permission;
use App\Support\SalesOrderQueuePermissions;

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
        return ['view', 'create', 'edit', 'delete', 'approve', 'deliver'];
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

    public static function ensure(): void
    {
        self::ensureRegistryPermissions();
        self::ensureCapabilityCodes();
        self::remapLegacyPermissionAssignments();
        self::remapLegacySalesOrderViewPermissions();
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

    public static function isRegistryModuleEnabled(string $registryModule, CapabilityGate $gate): bool
    {
        if ($registryModule === 'ai') {
            return $gate->aiPlatformEnabled();
        }
        if ($registryModule === 'mobile_sales') {
            return $gate->mobileSalesEnabled();
        }
        if ($registryModule === 'mobile_driver') {
            return $gate->driverMobileEnabled();
        }

        $erpKeys = self::erpModuleMap()[$registryModule] ?? [$registryModule];

        foreach ($erpKeys as $key) {
            if ($gate->enabled((string) $key)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> Permission ids whose registry module is enabled for the org. */
    public static function enabledPermissionIds(CapabilityGate $gate): array
    {
        self::ensure();

        return Permission::query()
            ->get()
            ->filter(fn (Permission $permission) => self::isRegistryModuleEnabled((string) $permission->module, $gate))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public static function groupedForUi(?CapabilityGate $gate = null): array
    {
        self::ensure();

        $byCode = Permission::query()->get()->keyBy('permission_code');
        $groups = [];

        foreach (config('permission_registry.groups', []) as $moduleKey => $groupDef) {
            if ($gate !== null && ! self::isRegistryModuleEnabled($moduleKey, $gate)) {
                continue;
            }
            $features = [];
            $orderQueueLabels = $moduleKey === 'sales' && $gate !== null
                ? SalesOrderQueuePermissions::labelsForGate($gate)
                : [];
            $activeOrderQueueKeys = $moduleKey === 'sales' && $gate !== null
                ? SalesOrderQueuePermissions::activeFeatureKeys($gate)
                : null;

            foreach ($groupDef['features'] as $featureKey => $featureDef) {
                if ($activeOrderQueueKeys !== null && str_starts_with($featureKey, 'order_queue_')) {
                    if (! in_array($featureKey, $activeOrderQueueKeys, true)) {
                        continue;
                    }
                }

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
                    'label' => $orderQueueLabels[$featureKey] ?? $featureDef['label'],
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
    public static function applicationsGroupedForUi(?CapabilityGate $gate = null): array
    {
        $groupsByModule = collect(self::groupedForUi($gate))->keyBy('module');
        $applications = [];

        foreach (config('permission_applications.order', []) as $appId) {
            $def = config("permission_applications.applications.{$appId}");
            if (! is_array($def)) {
                continue;
            }

            $modules = [];
            foreach ($def['registry_modules'] ?? [] as $registryModule) {
                $group = $groupsByModule->get($registryModule);
                if (is_array($group)) {
                    $modules[] = $group;
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

    /** Grant granular order-queue view permissions to roles that still have sales.orders.view. */
    public static function remapLegacySalesOrderViewPermissions(): void
    {
        $legacy = Permission::query()->where('permission_code', 'sales.orders.view')->first();
        if (! $legacy) {
            return;
        }

        $targetIds = Permission::query()
            ->whereIn('permission_code', SalesOrderQueuePermissions::allViewPermissionCodes())
            ->pluck('id', 'permission_code');

        if ($targetIds->isEmpty()) {
            return;
        }

        $roleIds = \Illuminate\Support\Facades\DB::table('role_permissions')
            ->where('permission_id', $legacy->id)
            ->pluck('role_id');

        foreach ($roleIds as $roleId) {
            foreach ($targetIds as $permissionId) {
                \Illuminate\Support\Facades\DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        \Illuminate\Support\Facades\DB::table('user_permission_overrides')
            ->where('permission_id', $legacy->id)
            ->where('effect', 'grant')
            ->get()
            ->each(function ($override) use ($targetIds) {
                foreach ($targetIds as $permissionId) {
                    \Illuminate\Support\Facades\DB::table('user_permission_overrides')->insertOrIgnore([
                        'user_id' => $override->user_id,
                        'permission_id' => $permissionId,
                        'effect' => 'grant',
                    ]);
                }
            });
    }
}
