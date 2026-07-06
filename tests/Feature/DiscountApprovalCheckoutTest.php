<?php

namespace Tests\Feature;

use App\Models\ActionRequest;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class DiscountApprovalCheckoutTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableDiscountApproval(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'discount_approval_enabled' => true,
            'enable_order_discount' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function salesStaff(): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $roleId = (int) (DB::table('roles')->where('role_name', 'Cashier')->value('id') ?? $admin->role_id);

        return User::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $roleId,
            'username' => 'discount_staff_'.uniqid(),
            'email' => null,
            'password' => $admin->password,
            'full_name' => 'Discount Staff',
            'is_admin' => false,
            'is_active' => true,
        ]);
    }

    protected function createCartWithLine(User $user): array
    {
        $product = Product::query()->firstOrFail();

        $cart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'backend',
            'order_source' => 'backoffice',
            'branch_id' => $user->branch_id,
        ])->assertCreated()->json();

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        return $cart;
    }

    public function test_checkout_with_pending_discount_creates_pending_approval_sale(): void
    {
        $this->enableDiscountApproval();
        $staff = $this->salesStaff();
        Sanctum::actingAs($staff);

        $cart = $this->createCartWithLine($staff);

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/discount-requests", [
            'scope' => 'order',
            'discount_amount' => 50,
            'reason' => 'Loyal customer discount',
        ])->assertAccepted();

        $sale = $this->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
            'save_only' => true,
        ])->assertCreated()
            ->assertJsonPath('status', 'pending_approval')
            ->json();

        $this->assertDatabaseHas('sales', [
            'id' => $sale['id'],
            'status' => 'pending_approval',
            'order_discount' => 50,
        ]);

        $this->assertDatabaseHas('action_requests', [
            'type' => 'discount',
            'reference_type' => 'sale',
            'reference_id' => $sale['id'],
            'status' => 'pending',
        ]);
    }

    public function test_approving_discount_moves_sale_to_booked(): void
    {
        $this->enableDiscountApproval();
        $staff = $this->salesStaff();
        Sanctum::actingAs($staff);

        $cart = $this->createCartWithLine($staff);

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/discount-requests", [
            'scope' => 'order',
            'discount_amount' => 25,
            'reason' => 'Promotional offer',
        ])->assertAccepted();

        $sale = $this->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
            'save_only' => true,
        ])->assertCreated()->json();

        $requestId = ActionRequest::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale['id'])
            ->value('id');

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/action-requests/{$requestId}/approve")
            ->assertOk();

        $this->assertSame('booked', Sale::query()->findOrFail($sale['id'])->status);
    }

    public function test_rejecting_discount_moves_sale_to_editable(): void
    {
        $this->enableDiscountApproval();
        $staff = $this->salesStaff();
        Sanctum::actingAs($staff);

        $cart = $this->createCartWithLine($staff);

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/discount-requests", [
            'scope' => 'order',
            'discount_amount' => 30,
            'reason' => 'Needs review',
        ])->assertAccepted();

        $sale = $this->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
            'save_only' => true,
        ])->assertCreated()->json();

        $requestId = ActionRequest::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale['id'])
            ->value('id');

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/action-requests/{$requestId}/reject", [
            'reason' => 'Discount too high',
        ])->assertOk();

        $this->assertSame('editable', Sale::query()->findOrFail($sale['id'])->status);
    }
}
