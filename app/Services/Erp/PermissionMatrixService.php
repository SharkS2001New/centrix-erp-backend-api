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
        return ['view', 'create', 'edit', 'delete', 'approve'];
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
    public static function applicationsForUi(?CapabilityGate $gate = null): array
    {
        $grouped = collect(self::groupedForUi($gate))->keyBy('module');
        $applications = [];

        foreach (config('permission_applications.order', []) as $applicationId) {
            $definition = config("permission_applications.applications.{$applicationId}");
            if (! is_array($definition)) {
                continue;
            }

            if ($gate !== null && ! self::isApplicationEnabled($applicationId, $gate)) {
                continue;
            }

            $modules = [];
            foreach ($definition['registry_modules'] ?? [] as $registryModule) {
                $moduleGroup = $grouped->get($registryModule);
                if ($moduleGroup !== null) {
                    $modules[] = $moduleGroup;
                }
            }

            if ($modules === []) {
                continue;
            }

            $applications[] = [
                'id' => $applicationId,
                'label' => (string) ($definition['label'] ?? $applicationId),
                'description' => (string) ($definition['description'] ?? ''),
                'standalone' => (bool) ($definition['standalone'] ?? false),
                'modules' => $modules,
            ];
        }

        return $applications;
    }

    public static function isApplicationEnabled(string $applicationId, CapabilityGate $gate): bool
    {
        if ($applicationId === 'mobile') {
            return $gate->mobileSalesEnabled();
        }

        foreach (config("permission_applications.applications.{$applicationId}.registry_modules", []) as $registryModule) {
            if (self::isRegistryModuleEnabled((string) $registryModule, $gate)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    public static function permissionIdsForApplication(string $applicationId, CapabilityGate $gate): array
    {
        self::ensure();

        $registryModules = config("permission_applications.applications.{$applicationId}.registry_modules", []);

        return Permission::query()
            ->get()
            ->filter(function (Permission $permission) use ($registryModules, $gate) {
                if (! in_array((string) $permission->module, $registryModules, true)) {
                    return false;
                }

                return self::isRegistryModuleEnabled((string) $permission->module, $gate);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
