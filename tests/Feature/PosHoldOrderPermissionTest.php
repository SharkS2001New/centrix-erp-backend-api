<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PosHoldOrderPermissionTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
        PermissionMatrixService::ensure();
    }

    public function test_till_operator_without_checkout_create_permission_can_hold_order(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $productCode = Product::query()->value('product_code');
        $this->assertNotEmpty($productCode);

        $role = Role::create([
            'role_name' => 'Till Operator',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $permissionCodes = [
            'pos.terminal.view',
            'pos.till_management.view',
            'pos.till_management.create',
            'catalogue.products.view',
        ];

        foreach ($permissionCodes as $code) {
            $permissionId = (int) Permission::where('permission_code', $code)->value('id');
            $this->assertGreaterThan(0, $permissionId, "Missing permission {$code}");
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
        }

        $cashier = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'till_operator',
            'password' => Hash::make('password'),
            'full_name' => 'Till Operator',
            'access_scope' => 'branch',
            'is_active' => true,
            'login_channels' => ['pos'],
        ]);

        $gate = app(ErpContext::class)->gateForUser($cashier);
        $permissions = app(UserPermissionService::class);

        $this->assertFalse($permissions->hasRoleAssignedPermission($cashier, 'pos.checkout.create'));
        $this->assertTrue($permissions->hasPermission($cashier, 'sales.create', $gate));

        Sanctum::actingAs($cashier);

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $cashier->branch_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'held',
            'pay_now' => 0,
            'save_only' => true,
            'deduct_stock' => true,
            'submit_kra' => false,
            'customer_name_override' => 'Walk-in',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'held');
    }
}
