<?php

namespace App\Services\Auth;

use App\Models\Permission;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\PermissionMatrixService;
use App\Support\SalesOrderQueuePermissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserPermissionService
{
    /** @return list<int> */
    public function rolePermissionIds(?int $roleId): array
    {
        if (! $roleId) {
            return [];
        }

        return DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function grantIds(int $userId): array
    {
        return $this->overrideIds($userId, 'grant');
    }

    /** @return list<int> */
    public function denyIds(int $userId): array
    {
        return $this->overrideIds($userId, 'deny');
    }

    /** @return list<int> */
    protected function overrideIds(int $userId, string $effect): array
    {
        return DB::table('user_permission_overrides')
            ->where('user_id', $userId)
            ->where('effect', $effect)
            ->pluck('permission_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function roleAssignedPermissionIds(User $user): array
    {
        $roleIds = collect($this->rolePermissionIds($user->role_id));
        $grants = collect($this->grantIds((int) $user->id));
        $denies = collect($this->denyIds((int) $user->id));

        return $roleIds
            ->merge($grants)
            ->diff($denies)
            ->unique()
            ->values()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return list<int> */
    public function effectivePermissionIds(User $user): array
    {
        if ($user->is_admin) {
            return Permission::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $roleIds = collect($this->rolePermissionIds($user->role_id));
        $grants = collect($this->grantIds((int) $user->id));
        $denies = collect($this->denyIds((int) $user->id));

        return $roleIds
            ->merge($grants)
            ->diff($denies)
            ->unique()
            ->values()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function hasPermission(User $user, string $permissionCode, ?CapabilityGate $gate = null): bool
    {
        if ($user->is_admin) {
            if ($gate === null) {
                return true;
            }

            if (
                in_array($permissionCode, ['admin.view', 'admin.manage'], true)
                && $gate->enabled('admin')
            ) {
                return true;
            }

            $permission = Permission::query()
                ->where('permission_code', $permissionCode)
                ->first();

            if (! $permission) {
                return true;
            }

            return PermissionMatrixService::isRegistryModuleEnabled((string) $permission->module, $gate);
        }

        if ($this->hasDirectPermission($user, $permissionCode)) {
            return true;
        }

        if (
            preg_match('/^sales\.order_queue_.+\.view$/', $permissionCode) === 1
            && $this->hasDirectPermission($user, 'sales.orders.view')
        ) {
            return true;
        }

        $aliases = config('permission_aliases', []);

        foreach ($aliases[$permissionCode] ?? [] as $aliasCode) {
            if ($this->hasDirectPermission($user, $aliasCode)) {
                return true;
            }
        }

        return false;
    }

  /** Whether the user holds a feature permission on their role/overrides (no capability aliases). */
    public function hasAssignedPermission(User $user, string $permissionCode): bool
    {
        return $this->hasRoleAssignedPermission($user, $permissionCode);
    }

    public function hasRoleAssignedPermission(User $user, string $permissionCode): bool
    {
        $permissionId = Permission::query()
            ->where('permission_code', $permissionCode)
            ->value('id');

        if (! $permissionId) {
            return false;
        }

        return in_array((int) $permissionId, $this->roleAssignedPermissionIds($user), true);
    }

    /** Managers who may approve discount requests submitted by other staff. */
    public function canApproveSalesOrders(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'sales.orders.approve');
    }

    /** Staff who may apply discounts directly without approval workflow or reason. */
    public function canGiveDiscountDirectly(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'sales.discounts.give');
    }

    protected function hasDirectPermission(User $user, string $permissionCode): bool
    {
        $permissionId = Permission::query()
            ->where('permission_code', $permissionCode)
            ->value('id');

        if (! $permissionId) {
            return false;
        }

        return in_array((int) $permissionId, $this->effectivePermissionIds($user), true);
    }

    /** @return array<string, bool> Feature permission codes assigned to the user (no capability alias expansion). */
    public function directPermissionMapForUser(User $user, ?CapabilityGate $gate = null): array
    {
        $permissions = Permission::query()
            ->whereIn('id', $this->roleAssignedPermissionIds($user))
            ->get();

        if ($gate !== null) {
            $permissions = $permissions->filter(
                fn (Permission $permission) => PermissionMatrixService::isRegistryModuleEnabled(
                    (string) $permission->module,
                    $gate,
                ),
            );
        }

        $map = [];
        foreach ($permissions as $permission) {
            $map[(string) $permission->permission_code] = true;
        }

        return $map;
    }

    /** @return array<string, bool> */
    public function permissionMapForUser(User $user, ?CapabilityGate $gate = null): array
    {
        $map = $this->expandCapabilityAliases($this->directPermissionMapForUser($user, $gate));
        $map = $this->expandLegacySalesOrderQueueView($map);

        if ($user->is_admin && $gate !== null) {
            $map = $this->grantOrgAdminMobileAppPermissions($map, $gate);
        }

        return $map;
    }

    /** Org administrators get every mobile sales/driver permission when those modules are enabled. */
    /** @param  array<string, bool>  $map
     * @return array<string, bool>
     */
    protected function grantOrgAdminMobileAppPermissions(array $map, CapabilityGate $gate): array
    {
        $aliases = config('permission_aliases', []);

        if ($gate->mobileSalesEnabled()) {
            foreach (['sales.create', 'mobile.access'] as $capability) {
                foreach ($aliases[$capability] ?? [] as $code) {
                    if (is_string($code) && str_starts_with($code, 'mobile_sales.')) {
                        $map[$code] = true;
                    }
                }
            }
        }

        if ($gate->driverMobileEnabled()) {
            foreach ($aliases['driver.mobile'] ?? [] as $code) {
                if (is_string($code) && str_starts_with($code, 'mobile_driver.')) {
                    $map[$code] = true;
                }
            }
        }

        return $map;
    }

    /** @param  array<string, bool>  $map
     * @return array<string, bool>
     */
    protected function expandLegacySalesOrderQueueView(array $map): array
    {
        if (! ($map['sales.orders.view'] ?? false)) {
            return $map;
        }

        foreach (SalesOrderQueuePermissions::allViewPermissionCodes() as $code) {
            $map[$code] = true;
        }

        return $map;
    }

    /** @param  array<string, bool>  $map
     * @return array<string, bool>
     */
    protected function expandCapabilityAliases(array $map): array
    {
        foreach (config('permission_aliases', []) as $capability => $aliases) {
            if ($map[$capability] ?? false) {
                continue;
            }
            foreach ($aliases as $alias) {
                if ($map[$alias] ?? false) {
                    $map[$capability] = true;
                    break;
                }
            }
        }

        return $map;
    }

    /** @param  list<int>  $grantedIds
     * @param  list<int>  $deniedIds
     */
    public function syncOverrides(int $userId, array $grantedIds, array $deniedIds): void
    {
        $grantedIds = array_values(array_unique(array_map('intval', $grantedIds)));
        $deniedIds = array_values(array_unique(array_map('intval', $deniedIds)));

        $overlap = array_intersect($grantedIds, $deniedIds);
        if ($overlap !== []) {
            throw ValidationException::withMessages([
                'permission_ids' => ['A permission cannot be both granted and denied for the same user.'],
            ]);
        }

        DB::transaction(function () use ($userId, $grantedIds, $deniedIds) {
            DB::table('user_permission_overrides')->where('user_id', $userId)->delete();

            foreach ($grantedIds as $permissionId) {
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $userId,
                    'permission_id' => $permissionId,
                    'effect' => 'grant',
                ]);
            }

            foreach ($deniedIds as $permissionId) {
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $userId,
                    'permission_id' => $permissionId,
                    'effect' => 'deny',
                ]);
            }
        });
    }

    /** @return Collection<int, User> */
    public function usersWithPermission(int $organizationId, string $permissionCode): Collection
    {
        return $this->usersWithAssignedPermission($organizationId, $permissionCode);
    }

    /** @return Collection<int, User> */
    public function usersWithAssignedPermission(int $organizationId, string $permissionCode): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $this->hasRoleAssignedPermission($user, $permissionCode))
            ->values();
    }

    /** @return array<string, mixed> */
    public function describeForUser(User $user): array
    {
        return [
            'user_id' => (int) $user->id,
            'role_id' => (int) $user->role_id,
            'role_permission_ids' => $this->rolePermissionIds($user->role_id),
            'granted_permission_ids' => $this->grantIds((int) $user->id),
            'denied_permission_ids' => $this->denyIds((int) $user->id),
            'effective_permission_ids' => $this->effectivePermissionIds($user),
        ];
    }
}
