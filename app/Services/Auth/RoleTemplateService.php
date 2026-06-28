<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ModuleRegistry;
use Illuminate\Support\Facades\DB;

class RoleTemplateService
{
    /** Ensure all configured role templates exist with up-to-date permissions. */
    public function ensureAllRoles(): void
    {
        foreach ($this->roleDefinitions() as $roleName => $definition) {
            $this->ensureRole($roleName, $definition);
        }

        $this->migrateLegacyStockClerkRole();
    }

    /**
     * @return list<array{role_name: string, scope: string, description: string}>
     */
    public function recommendedForOrganization(Organization $org): array
    {
        $gate = app(CapabilityGate::class)->forOrganization($org);
        $modules = $gate->allModules();

        return $this->recommendedForProfile(
            (string) $org->deployment_profile,
            $modules,
        );
    }

    /**
     * @param  array<string, bool>  $enabledModules
     * @return list<array{role_name: string, scope: string, description: string}>
     */
    public function recommendedForProfile(string $profile, array $enabledModules): array
    {
        $names = $this->resolveRoleNamesForProfile($profile, $enabledModules);
        $definitions = $this->roleDefinitions();
        $result = [];

        foreach ($names as $name) {
            $definition = $definitions[$name] ?? null;
            if ($definition === null) {
                continue;
            }

            if (! $this->roleMatchesModules($definition, $enabledModules)) {
                continue;
            }

            $result[] = [
                'role_name' => $name,
                'scope' => (string) ($definition['scope'] ?? 'branch'),
                'description' => (string) ($definition['description'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, bool>  $enabledModules
     * @return list<string>
     */
    public function onboardingSteps(string $profile, array $enabledModules): array
    {
        $steps = [
            'Sign in as the administrator and change the default password if needed.',
        ];

        if ($enabledModules['admin'] ?? false) {
            $steps[] = 'Open Administration → Users and create staff accounts using the recommended roles below.';
        } else {
            $steps[] = 'Use Platform → Organization → Users to create staff accounts with the recommended roles below.';
        }

        if ($enabledModules['sales.pos'] ?? false) {
            $steps[] = 'Configure payment methods and open a till session before the first POS sale.';
        }

        if ($enabledModules['sales.mobile'] ?? false) {
            $steps[] = 'Assign mobile login channels to field reps and link them to routes.';
        }

        if ($enabledModules['distribution'] ?? false) {
            $steps[] = 'Set up routes, drivers, vehicles, and route schedules before dispatching orders.';
        }

        if ($enabledModules['inventory'] ?? false) {
            $steps[] = 'Import or create products, then post an opening stock receipt or adjustment.';
        }

        if ($enabledModules['accounting'] ?? false) {
            $steps[] = 'Review the chart of accounts and fiscal year under Accounting.';
        }

        if ($profile === 'custom') {
            $steps[] = 'Review enabled applications on the login screen and adjust modules if anything is missing.';
        }

        return $steps;
    }

    /** @return array<string, array<string, mixed>> */
    protected function roleDefinitions(): array
    {
        return config('role_templates.roles', []);
    }

    /** @param  array<string, bool>  $enabledModules */
    protected function resolveRoleNamesForProfile(string $profile, array $enabledModules): array
    {
        $profileRoles = config("role_templates.profile_roles.{$profile}")
            ?? config('role_templates.profile_roles.custom', ['Branch Manager', 'Viewer']);

        $names = $profileRoles;

        if ($profile === 'custom') {
            foreach (config('role_templates.module_roles', []) as $moduleKey => $roleNames) {
                if ($enabledModules[$moduleKey] ?? false) {
                    $names = array_merge($names, $roleNames);
                }
            }
        }

        $ordered = [];
        foreach (array_keys($this->roleDefinitions()) as $canonical) {
            if (in_array($canonical, $names, true)) {
                $ordered[] = $canonical;
            }
        }

        return array_values(array_unique($ordered));
    }

    /** @param  array<string, mixed>  $definition */
    protected function roleMatchesModules(array $definition, array $enabledModules): bool
    {
        $required = (array) ($definition['requires_modules'] ?? []);
        if ($required === []) {
            return true;
        }

        foreach ($required as $moduleKey) {
            if ($enabledModules[$moduleKey] ?? false) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $definition */
    protected function ensureRole(string $roleName, array $definition): Role
    {
        $role = Role::query()->firstOrCreate(
            ['role_name' => $roleName],
            [
                'scope' => (string) ($definition['scope'] ?? 'branch'),
                'is_active' => true,
            ],
        );

        if ($role->scope !== ($definition['scope'] ?? 'branch')) {
            $role->update(['scope' => (string) $definition['scope']]);
        }

        $permissionIds = Permission::query()
            ->whereIn('permission_code', (array) ($definition['permissions'] ?? []))
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
        }

        return $role;
    }

    /** Keep legacy Stock Clerk role aligned with Warehouse Clerk for existing tenants. */
    protected function migrateLegacyStockClerkRole(): void
    {
        $warehouse = $this->roleDefinitions()['Warehouse Clerk'] ?? null;
        if ($warehouse === null) {
            return;
        }

        $legacy = Role::query()->where('role_name', 'Stock Clerk')->first();
        if (! $legacy) {
            return;
        }

        $permissionIds = Permission::query()
            ->whereIn('permission_code', (array) ($warehouse['permissions'] ?? []))
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $legacy->id,
                'permission_id' => $permissionId,
            ]);
        }
    }

    /**
     * @param  array<string, bool>|null  $sparseModules
     * @return array<string, bool>
     */
    public function expandEnabledModules(?array $sparseModules, string $profile): array
    {
        if ($sparseModules !== null && $sparseModules !== []) {
            return ModuleRegistry::cascade(ModuleRegistry::sanitizeModuleMap($sparseModules));
        }

        $profileModules = config("erp.profiles.{$profile}.modules", []);

        return ModuleRegistry::cascade(ModuleRegistry::sanitizeModuleMap($profileModules));
    }
}
