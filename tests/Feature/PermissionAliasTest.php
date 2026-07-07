<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PermissionAliasTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_sales_orders_edit_does_not_grant_discount_approval(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Sales Editor',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $editId = (int) Permission::where('permission_code', 'sales.orders.edit')->value('id');
        $this->assertNotNull($editId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $editId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'sales_editor',
            'password' => Hash::make('password'),
            'full_name' => 'Sales Editor',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        $service = app(UserPermissionService::class);
        $this->assertTrue($service->hasPermission($user->fresh(), 'sales.manage'));
        $this->assertFalse($service->canApproveSalesOrders($user->fresh()));
        $this->assertFalse(app(\App\Services\Sales\DiscountApprovalService::class)
            ->canAutoApproveDiscount($user->fresh()));
    }

    public function test_sales_orders_approve_does_not_grant_direct_discount(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Sales Approver',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $approveId = (int) Permission::where('permission_code', 'sales.orders.approve')->value('id');
        $giveId = (int) Permission::where('permission_code', 'sales.discounts.give')->value('id');
        $this->assertNotNull($approveId);
        $this->assertNotNull($giveId);

        DB::table('role_permissions')->insert([
            ['role_id' => $role->id, 'permission_id' => $approveId],
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'sales_approver_only',
            'password' => Hash::make('password'),
            'full_name' => 'Sales Approver Only',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        $service = app(UserPermissionService::class);
        $this->assertTrue($service->canApproveSalesOrders($user->fresh()));
        $this->assertFalse($service->canGiveDiscountDirectly($user->fresh()));
        $this->assertFalse(app(\App\Services\Sales\DiscountApprovalService::class)
            ->canAutoApproveDiscount($user->fresh()));
    }

    public function test_sales_discounts_give_allows_direct_discount_without_approval(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Discount Manager',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $giveId = (int) Permission::where('permission_code', 'sales.discounts.give')->value('id');
        $this->assertNotNull($giveId);

        DB::table('role_permissions')->insert([
            ['role_id' => $role->id, 'permission_id' => $giveId],
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'discount_manager',
            'password' => Hash::make('password'),
            'full_name' => 'Discount Manager',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        $service = app(UserPermissionService::class);
        $this->assertFalse($service->canApproveSalesOrders($user->fresh()));
        $this->assertTrue($service->canGiveDiscountDirectly($user->fresh()));
        $this->assertTrue(app(\App\Services\Sales\DiscountApprovalService::class)
            ->canAutoApproveDiscount($user->fresh()));
    }

    public function test_feature_edit_permission_satisfies_route_capability(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Sales Editor',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $editId = (int) Permission::where('permission_code', 'sales.orders.edit')->value('id');
        $this->assertNotNull($editId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $editId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'sales_editor',
            'password' => Hash::make('password'),
            'full_name' => 'Sales Editor',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        $service = app(UserPermissionService::class);
        $this->assertTrue($service->hasPermission($user->fresh(), 'sales.manage'));
        $this->assertTrue($service->permissionMapForUser($user->fresh())['sales.manage']);
    }

    public function test_is_admin_without_approve_permission_cannot_approve_discounts(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Admin Shell',
            'scope' => 'org',
            'is_active' => true,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'admin_shell',
            'password' => Hash::make('password'),
            'full_name' => 'Admin Shell',
            'access_scope' => 'org',
            'is_admin' => true,
            'is_active' => true,
        ]);

        $service = app(UserPermissionService::class);
        $this->assertFalse($service->canApproveSalesOrders($user->fresh()));
        $this->assertFalse($service->canGiveDiscountDirectly($user->fresh()));
    }

    public function test_administrator_role_receives_discount_approve_on_sync(): void
    {
        PermissionMatrixService::ensure();

        $approveId = (int) Permission::where('permission_code', 'sales.orders.approve')->value('id');
        $this->assertNotNull($approveId);

        $adminRole = Role::query()->where('role_name', 'Administrator')->first();
        $this->assertNotNull($adminRole);

        $this->assertTrue(
            DB::table('role_permissions')
                ->where('role_id', $adminRole->id)
                ->where('permission_id', $approveId)
                ->exists()
        );
    }

    public function test_catalogue_product_edit_satisfies_products_manage(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Catalogue Editor',
            'scope' => 'org',
            'is_active' => true,
        ]);

        $editId = (int) Permission::where('permission_code', 'catalogue.products.edit')->value('id');
        $this->assertNotNull($editId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $editId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'catalogue_editor',
            'password' => Hash::make('password'),
            'full_name' => 'Catalogue Editor',
            'access_scope' => 'org',
            'is_active' => true,
        ]);

        $service = app(UserPermissionService::class);
        $this->assertTrue($service->hasPermission($user->fresh(), 'products.manage'));
    }
}
