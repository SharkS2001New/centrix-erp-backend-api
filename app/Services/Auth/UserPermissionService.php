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

        if ($gate !== null && $this->managerSessionGrantsPermission($user, $permissionCode, $gate)) {
            return true;
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

    /** Managers who may approve or reject sales order cancellation requests. */
    public function canApproveSalesOrders(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'sales.orders.approve');
    }

    /** Managers who may approve or reject discount approval requests. */
    public function canApproveDiscountRequests(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'admin.discount_approvals.approve')
            || $this->hasRoleAssignedPermission($user, 'sales.orders.approve');
    }

    /** True when the user holds any role-assigned permission that expands to the capability alias. */
    public function hasAssignedCapability(User $user, string $capability): bool
    {
        foreach (config('permission_aliases', [])[$capability] ?? [] as $code) {
            if ($this->hasRoleAssignedPermission($user, (string) $code)) {
                return true;
            }
        }

        return false;
    }

    public function canApproveLeaveRequests(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'hr.leave.approve')
            || $this->hasAssignedCapability($user, 'hr.manage');
    }

    public function canApprovePayrollRuns(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'hr.payroll.approve')
            || $this->hasAssignedCapability($user, 'hr.manage');
    }

    public function canApproveCashAdvances(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'hr.cash_advances.approve')
            || $this->hasAssignedCapability($user, 'hr.manage');
    }

    public function canApproveOrderCancellations(User $user): bool
    {
        return $this->canApproveSalesOrders($user)
            || $this->hasAssignedCapability($user, 'sales.manage');
    }

    public function canDirectCancelOrders(User $user): bool
    {
        return (bool) $user->is_admin
            || $this->hasPermission($user, 'sales.manage');
    }

    /** Managers who may re-edit or revise another user's sales order. */
    public function canEditOthersSalesOrders(User $user, ?CapabilityGate $gate = null): bool
    {
        return $this->hasPermission($user, 'sales.orders.edit', $gate)
            || $this->hasPermission($user, 'sales.manage', $gate);
    }

    public function canApproveSupplierReturns(User $user): bool
    {
        return $this->hasAssignedCapability($user, 'purchasing.manage');
    }

    public function canApproveCustomerReturns(User $user): bool
    {
        return $this->hasAssignedCapability($user, 'sales.manage');
    }

    public function canApproveInventoryOperations(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'inventory.manage')
            || $this->hasRoleAssignedPermission($user, 'inventory.stock_take.approve');
    }

    public function canApproveStockTakeCompletions(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'inventory.stock_take.approve')
            || $this->hasRoleAssignedPermission($user, 'inventory.manage');
    }

    public function canApproveJournalEntries(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'accounting.journal_entries.approve')
            || $this->hasRoleAssignedPermission($user, 'accounting.manage');
    }

    public function canApproveExpenses(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'accounting.manage');
    }

    public function canApproveLpoRequests(User $user): bool
    {
        return $this->hasRoleAssignedPermission($user, 'purchasing.lpo.approve')
            || $this->hasAssignedCapability($user, 'purchasing.manage');
    }

    public function canDirectInventoryAction(User $user): bool
    {
        return (bool) $user->is_admin || $this->hasRoleAssignedPermission($user, 'inventory.manage');
    }

    /** Direct inventory manage (role-assigned capability), not create-only alias children. */
    public function canDirectManageInventory(User $user): bool
    {
        return (bool) $user->is_admin
            || $this->hasRoleAssignedPermission($user, 'inventory.manage')
            || $this->hasRoleAssignedPermission($user, 'inventory.stock_take.approve');
    }

    /** Explicit approval rights for UI and API (no capability-alias expansion). */
    /** @return array<string, bool> */
    public function approvalCapabilitiesForUser(User $user): array
    {
        return [
            'discount_requests' => $this->canApproveDiscountRequests($user),
            'sales_orders' => $this->canApproveSalesOrders($user),
            'order_cancellations' => $this->canApproveOrderCancellations($user),
            'leave_requests' => $this->canApproveLeaveRequests($user),
            'payroll_runs' => $this->canApprovePayrollRuns($user),
            'cash_advances' => $this->canApproveCashAdvances($user),
            'supplier_returns' => $this->canApproveSupplierReturns($user),
            'customer_returns' => $this->canApproveCustomerReturns($user),
            'inventory_operations' => $this->canApproveInventoryOperations($user),
            'stock_take_completions' => $this->canApproveStockTakeCompletions($user),
            'journal_entries' => $this->canApproveJournalEntries($user),
            'expenses' => $this->canApproveExpenses($user),
            'lpo_requests' => $this->canApproveLpoRequests($user),
            'direct_cancel_orders' => $this->canDirectCancelOrders($user),
            'direct_inventory_actions' => $this->canDirectInventoryAction($user),
        ];
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
            $map = $this->grantOrgAdminEnabledModulePermissions($map, $gate);
            $map = $this->grantOrgAdminMobileAppPermissions($map, $gate);
        }

        if ($gate !== null) {
            $map = $this->grantManagerAppBundlePermissions($map, $gate);
        }

        return $map;
    }

    /**
     * Org administrators receive every feature permission for enabled registry modules.
     * Mirrors middleware hasPermission() module gating for the capabilities payload.
     *
     * @param  array<string, bool>  $map
     * @return array<string, bool>
     */
    protected function grantOrgAdminEnabledModulePermissions(array $map, CapabilityGate $gate): array
    {
        foreach (Permission::query()->get() as $permission) {
            if (! PermissionMatrixService::isRegistryModuleEnabled((string) $permission->module, $gate)) {
                continue;
            }

            $map[(string) $permission->permission_code] = true;
        }

        $map = $this->expandCapabilityAliases($map);

        return $this->expandLegacySalesOrderQueueView($map);
    }

    /** @param  array<string, bool>  $map
     * @return array<string, bool>
     */
    protected function grantManagerAppBundlePermissions(array $map, CapabilityGate $gate): array
    {
        if (! ($map['mobile_manager.app.access'] ?? false) || ! $gate->managerAppEnabled()) {
            return $map;
        }

        foreach (Permission::query()->where('module', 'mobile_manager')->pluck('permission_code') as $code) {
            $map[(string) $code] = true;
        }

        $aliases = config('permission_aliases', []);

        if (PermissionMatrixService::isRegistryModuleEnabled('reports', $gate)) {
            foreach ($aliases['reports.view'] ?? [] as $code) {
                $map[(string) $code] = true;
            }
        }

        if ($gate->distributionOpsEnabled()) {
            foreach ($aliases['fulfillment.view'] ?? [] as $code) {
                $map[(string) $code] = true;
            }
        }

        if (PermissionMatrixService::isRegistryModuleEnabled('catalogue', $gate)) {
            foreach ($aliases['catalogue.view'] ?? [] as $code) {
                $map[(string) $code] = true;
            }
            foreach ($aliases['products.manage'] ?? [] as $code) {
                $map[(string) $code] = true;
            }
        }

        if ($gate->enabled('customers_suppliers')) {
            foreach ($aliases['customers.view'] ?? [] as $code) {
                $map[(string) $code] = true;
            }
        }

        if ($gate->enabled('sales.backend')) {
            foreach ($aliases['sales.view'] ?? [] as $code) {
                $map[(string) $code] = true;
            }
        }

        if (PermissionMatrixService::isRegistryModuleEnabled('inventory', $gate)) {
            foreach ($aliases['inventory.view'] ?? [] as $code) {
                $map[(string) $code] = true;
            }
        }

        if (PermissionMatrixService::isRegistryModuleEnabled('accounting', $gate)) {
            foreach ($aliases['accounting.view'] ?? [] as $code) {
                $map[(string) $code] = true;
            }
        }

        if (PermissionMatrixService::isRegistryModuleEnabled('hr', $gate)) {
            foreach ($aliases['hr.view'] ?? [] as $code) {
                $map[(string) $code] = true;
            }
        }

        if (PermissionMatrixService::isRegistryModuleEnabled('purchasing', $gate)) {
            foreach ($aliases['purchasing.view'] ?? [] as $code) {
                $map[(string) $code] = true;
            }
        }

        $map = $this->expandCapabilityAliases($map);

        return $this->expandLegacySalesOrderQueueView($map);
    }

    protected function managerSessionGrantsPermission(
        User $user,
        string $permissionCode,
        CapabilityGate $gate,
    ): bool {
        if (! $gate->managerAppEnabled() || ! $this->hasDirectPermission($user, 'mobile_manager.app.access')) {
            return false;
        }

        if (str_starts_with($permissionCode, 'mobile_manager.')) {
            return true;
        }

        if (
            str_starts_with($permissionCode, 'reports.')
            && PermissionMatrixService::isRegistryModuleEnabled('reports', $gate)
        ) {
            return true;
        }

        if ($gate->distributionOpsEnabled() && $this->permissionInAliasGroup($permissionCode, 'fulfillment.view')) {
            return true;
        }

        if (
            PermissionMatrixService::isRegistryModuleEnabled('catalogue', $gate)
            && (
                $this->permissionInAliasGroup($permissionCode, 'catalogue.view')
                || $this->permissionInAliasGroup($permissionCode, 'products.manage')
            )
        ) {
            return true;
        }

        if ($gate->enabled('customers_suppliers') && $this->permissionInAliasGroup($permissionCode, 'customers.view')) {
            return true;
        }

        if ($gate->enabled('sales.backend') && $this->permissionInAliasGroup($permissionCode, 'sales.view')) {
            return true;
        }

        if (PermissionMatrixService::isRegistryModuleEnabled('inventory', $gate) && $this->permissionInAliasGroup($permissionCode, 'inventory.view')) {
            return true;
        }

        if (PermissionMatrixService::isRegistryModuleEnabled('accounting', $gate) && $this->permissionInAliasGroup($permissionCode, 'accounting.view')) {
            return true;
        }

        if (PermissionMatrixService::isRegistryModuleEnabled('hr', $gate) && $this->permissionInAliasGroup($permissionCode, 'hr.view')) {
            return true;
        }

        if (PermissionMatrixService::isRegistryModuleEnabled('purchasing', $gate) && $this->permissionInAliasGroup($permissionCode, 'purchasing.view')) {
            return true;
        }

        return false;
    }

    /** @param  list<string>  $codes */
    protected function permissionInAliasGroup(string $permissionCode, string $aliasKey): bool
    {
        return in_array($permissionCode, config('permission_aliases')[$aliasKey] ?? [], true);
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
    public function usersWhoCanApproveDiscountRequests(int $organizationId): Collection
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $this->canApproveDiscountRequests($user))
            ->values();
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
