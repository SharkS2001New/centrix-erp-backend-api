<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrderCancellationApprovalTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableCancellationApproval(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'order_cancellation_approval_enabled' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function salesStaff(): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Cancellation Test Rep'],
            ['scope' => 'branch', 'is_active' => true],
        );

        $permissionIds = Permission::query()
            ->whereIn('permission_code', [
                'sales.orders.create',
                'sales.orders.edit',
                'mobile_sales.orders.create',
                'mobile_sales.orders.edit',
            ])
            ->pluck('id')
            ->all();
        $this->assertNotEmpty($permissionIds);

        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => (int) $permissionId,
            ]);
        }

        return User::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'cancel_staff_'.uniqid(),
            'email' => null,
            'password' => $admin->password,
            'full_name' => 'Cancel Staff',
            'is_admin' => false,
            'is_active' => true,
        ]);
    }

    protected function createBookedMobileSale(User $user): Sale
    {
        $product = Product::query()->firstOrFail();

        $cart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'order_source' => 'mobile',
            'branch_id' => $user->branch_id,
        ])->assertCreated()->json();

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $checkout = $this->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ])->assertCreated()->json();

        return Sale::query()->findOrFail((int) $checkout['id']);
    }

    public function test_staff_cannot_direct_cancel_when_approval_required(): void
    {
        $this->enableCancellationApproval();
        $staff = $this->salesStaff();
        Sanctum::actingAs($staff);

        $sale = $this->createBookedMobileSale($staff);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/cancel")
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Order cancellation requires manager approval. Submit a cancellation request instead.',
            ]);

        $this->assertSame('booked', $sale->fresh()->status);
    }

    public function test_staff_can_request_cancellation_when_approval_required(): void
    {
        $this->enableCancellationApproval();
        $staff = $this->salesStaff();
        Sanctum::actingAs($staff);

        $sale = $this->createBookedMobileSale($staff);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/request-cancellation", [
            'reason' => 'Customer changed mind',
        ])->assertStatus(202)
            ->assertJsonPath('pending_approval', true);

        $this->assertSame('booked', $sale->fresh()->status);
    }

    public function test_mobile_order_detail_exposes_cancellation_flags(): void
    {
        $this->enableCancellationApproval();
        $staff = $this->salesStaff();
        Sanctum::actingAs($staff);

        $sale = $this->createBookedMobileSale($staff);

        $this->getJson("/api/v1/mobile/orders/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('can_cancel', true)
            ->assertJsonPath('can_direct_cancel', false)
            ->assertJsonPath('can_request_cancellation', true);
    }
}
