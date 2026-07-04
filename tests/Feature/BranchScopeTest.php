<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\PermissionMatrixService;
use Database\Seeders\ProductionRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BranchScopeTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_branch_limited_user_only_sees_own_branch_sales(): void
    {
        PermissionMatrixService::ensure();
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        $otherBranchId = (int) DB::table('branches')
            ->where('organization_id', $orgId)
            ->where('id', '!=', $admin->branch_id)
            ->value('id');

        if (! $otherBranchId) {
            $otherBranchId = (int) DB::table('branches')->insertGetId([
                'organization_id' => $orgId,
                'branch_code' => 'BR2',
                'branch_name' => 'Branch Two',
                'branch_type' => 'retail',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Sale::query()->create([
            'order_num' => 991001,
            'branch_id' => $otherBranchId,
            'organization_id' => $orgId,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 100,
            'amount_paid' => 100,
        ]);

        Sale::query()->create([
            'order_num' => 991002,
            'branch_id' => $admin->branch_id,
            'organization_id' => $orgId,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 50,
            'amount_paid' => 50,
        ]);

        $role = Role::create([
            'role_name' => 'Branch Sales Viewer',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $viewId = (int) Permission::where('permission_code', 'sales.order_queue_all.view')->value('id');
        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $viewId,
        ]);

        $user = User::create([
            'organization_id' => $orgId,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'branch_sales_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Branch Sales Viewer',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $orders = collect($this->getJson('/api/v1/sales?per_page=100')->assertOk()->json('data'));

        $this->assertTrue($orders->contains(fn ($row) => (int) $row['order_num'] === 991002));
        $this->assertFalse($orders->contains(fn ($row) => (int) $row['order_num'] === 991001));
    }

    public function test_production_role_seeder_grants_viewer_permissions(): void
    {
        PermissionMatrixService::ensure();
        $this->seed(ProductionRoleSeeder::class);

        $role = Role::where('role_name', 'Viewer')->firstOrFail();
        $codes = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $role->id)
            ->pluck('permissions.permission_code');

        $this->assertTrue($codes->contains('sales.order_queue_all.view'));
        $this->assertTrue($codes->contains('reports.hub.view'));
        $this->assertFalse($codes->contains('purchasing.manage'));
    }

    public function test_branch_user_cannot_create_cart_for_other_branch(): void
    {
        PermissionMatrixService::ensure();
        [$user, $otherBranchId] = $this->branchCashierWithSalesCreate();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $otherBranchId,
        ])->assertForbidden();

        $cart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
        ])->assertCreated()->json();

        $this->assertSame((int) $user->branch_id, (int) $cart['branch_id']);
    }

    public function test_branch_user_cannot_restore_held_order_from_other_branch(): void
    {
        PermissionMatrixService::ensure();
        $admin = User::where('username', 'admin')->firstOrFail();
        [$user, $otherBranchId] = $this->branchCashierWithSalesCreate();

        $held = Sale::query()->create([
            'order_num' => 991003,
            'branch_id' => $otherBranchId,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'held',
            'payment_status' => 'unpaid',
            'order_total' => 100,
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/sales/orders/{$held->id}/restore-to-cart")
            ->assertNotFound();
    }

    /** @return array{0: User, 1: int} */
    protected function branchCashierWithSalesCreate(): array
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        $otherBranchId = (int) DB::table('branches')
            ->where('organization_id', $orgId)
            ->where('id', '!=', $admin->branch_id)
            ->value('id');

        if (! $otherBranchId) {
            $otherBranchId = (int) DB::table('branches')->insertGetId([
                'organization_id' => $orgId,
                'branch_code' => 'BR2',
                'branch_name' => 'Branch Two',
                'branch_type' => 'retail',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $role = Role::create([
            'role_name' => 'Branch Cashier '.uniqid(),
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $createId = (int) Permission::where('permission_code', 'sales.create')->value('id');
        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $createId,
        ]);

        $user = User::create([
            'organization_id' => $orgId,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'branch_cashier_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Branch Cashier',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        return [$user, $otherBranchId];
    }
}
