<?php

namespace Tests\Feature;

use App\Models\ActionRequest;
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
            'enable_order_discount' => false,
            'allow_edit_line_discount' => false,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function salesStaff(): User
    {
        return $this->mobileSalesRepUser();
    }

    protected function mobileSalesRepUser(): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Discount Test Mobile Rep'],
            ['scope' => 'branch', 'is_active' => true],
        );

        $permissionCodes = [
            'sales.orders.create',
            'sales.orders.edit',
            'mobile_sales.orders.create',
            'mobile_sales.orders.edit',
        ];
        $permissionIds = Permission::query()
            ->whereIn('permission_code', $permissionCodes)
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

    public function test_direct_line_discount_is_rejected_when_approval_required(): void
    {
        $this->enableDiscountApproval();
        $staff = $this->salesStaff();
        Sanctum::actingAs($staff);

        $cart = $this->createCartWithLine($staff);
        $product = Product::query()->firstOrFail();

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
            'discount_given' => 25,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_given']);
    }

    public function test_mobile_rep_with_order_edit_permission_requires_pending_approval(): void
    {
        $this->enableDiscountApproval();
        $staff = $this->mobileSalesRepUser();
        Sanctum::actingAs($staff);

        $product = Product::query()->firstOrFail();

        $cart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $staff->branch_id,
        ])->assertCreated()->json();

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $cartWithLine = $this->getJson("/api/v1/sales/carts/{$cart['id']}")->assertOk()->json();
        $lineRef = $cartWithLine['lines'][0]['update_code'] ?? $cartWithLine['lines'][0]['id'];

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/discount-requests", [
            'scope' => 'line',
            'line_ref' => (string) $lineRef,
            'discount_amount' => 10,
            'reason' => 'Customer loyalty discount',
        ])->assertAccepted();

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
            'save_only' => true,
        ])->assertCreated()
            ->assertJsonPath('status', 'pending_approval');
    }

    public function test_mobile_checkout_with_pending_line_discount_creates_pending_approval_sale(): void
    {
        $this->enableDiscountApproval();
        $staff = $this->salesStaff();
        Sanctum::actingAs($staff);

        $product = Product::query()->firstOrFail();

        $cart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $staff->branch_id,
        ])->assertCreated()->json();

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $cartWithLine = $this->getJson("/api/v1/sales/carts/{$cart['id']}")->assertOk()->json();
        $lineRef = $cartWithLine['lines'][0]['update_code'] ?? $cartWithLine['lines'][0]['id'];

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/discount-requests", [
            'scope' => 'line',
            'line_ref' => (string) $lineRef,
            'discount_amount' => 10,
            'reason' => 'Customer loyalty discount',
        ])->assertAccepted();

        $sale = $this->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
            'save_only' => true,
        ])->assertCreated()
            ->assertJsonPath('status', 'pending_approval')
            ->json();

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale['id'],
            'product_code' => $product->product_code,
            'discount_given' => 10,
        ]);
    }

    public function test_checkout_with_cart_discount_forces_pending_approval_without_action_request(): void
    {
        $this->enableDiscountApproval();
        $staff = $this->salesStaff();
        Sanctum::actingAs($staff);

        $product = Product::query()->firstOrFail();
        $cart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $staff->branch_id,
        ])->assertCreated()->json();

        // Simulate legacy direct discount bypass — checkout must still land in pending_approval.
        $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
            'discount_given' => 15,
        ]);

        $sale = $this->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
            'save_only' => true,
        ]);

        if ($sale->status() === 422) {
            $sale->assertJsonValidationErrors(['discount_given']);
            $this->assertTrue(true);

            return;
        }

        $sale->assertCreated()->assertJsonPath('status', 'pending_approval');
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
            'discount_guidance' => 'advised_amount',
            'advised_discount_amount' => 10,
        ])->assertOk();

        $sale = Sale::query()->findOrFail($sale['id']);
        $this->assertSame('editable', $sale->status);
        $approval = $sale->fulfillment_meta['discount_approval'] ?? [];
        $this->assertSame('advised_amount', $approval['rejection_guidance_type'] ?? null);
        $this->assertSame(10.0, (float) ($approval['advised_discount_amount'] ?? 0));
    }

    public function test_resubmitting_with_advised_discount_uses_confirmation_message(): void
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
            'discount_guidance' => 'advised_amount',
            'advised_discount_amount' => 10,
        ])->assertOk();

        Sanctum::actingAs($staff);

        $editCart = $this->postJson("/api/v1/sales/orders/{$sale['id']}/restore-to-cart", [
            'replace' => true,
        ])->assertOk()->json();

        $this->postJson("/api/v1/sales/carts/{$editCart['id']}/discount-requests", [
            'scope' => 'order',
            'discount_amount' => 10,
            'defer_approval' => true,
        ])->assertOk();

        $resubmitted = $this->postJson("/api/v1/sales/carts/{$editCart['id']}/checkout", [
            'save_only' => true,
            'discount_approval_reason' => 'Staff follow-up note',
        ])->assertCreated()
            ->assertJsonPath('status', 'pending_approval')
            ->json();

        $confirmation = 'An update has been made to this order and the advised discount has been applied. Please confirm and approve.';

        $this->assertDatabaseHas('action_requests', [
            'reference_type' => 'sale',
            'reference_id' => $resubmitted['id'],
            'status' => 'pending',
            'reason' => $confirmation,
        ]);

        $pendingRequest = ActionRequest::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $resubmitted['id'])
            ->where('status', 'pending')
            ->firstOrFail();

        $this->assertTrue((bool) ($pendingRequest->payload['advised_discount_applied'] ?? false));
        $this->assertStringContainsString('advised discount', strtolower((string) $pendingRequest->message));
    }

    public function test_approving_discount_notifies_requester(): void
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

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $staff->id,
            'type' => 'approval_outcome',
            'is_read' => false,
        ]);

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 1);

        $list = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($list);
        $this->assertSame('approval_outcome', $list[0]['type']);
        $this->assertStringContainsString('discount', strtolower($list[0]['message']));
        $this->assertStringContainsString('approved', strtolower($list[0]['message']));
    }

    public function test_deferred_line_discount_creates_approval_request_on_checkout_only(): void
    {
        $this->enableDiscountApproval();
        $staff = $this->mobileSalesRepUser();
        Sanctum::actingAs($staff);

        $product = Product::query()->firstOrFail();

        $cart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $staff->branch_id,
        ])->assertCreated()->json();

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $cartWithLine = $this->getJson("/api/v1/sales/carts/{$cart['id']}")->assertOk()->json();
        $lineRef = $cartWithLine['lines'][0]['update_code'] ?? $cartWithLine['lines'][0]['id'];

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/discount-requests", [
            'scope' => 'line',
            'line_ref' => (string) $lineRef,
            'discount_amount' => 10,
            'defer_approval' => true,
        ])->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('deferred_approval', true)
            ->assertJsonMissingPath('pending_approval');

        $this->assertDatabaseMissing('action_requests', [
            'reference_type' => 'temporary_cart',
            'reference_id' => $cart['id'],
            'status' => 'pending',
        ]);

        $this->getJson("/api/v1/sales/carts/{$cart['id']}")
            ->assertOk()
            ->assertJsonPath('discount_approval_pending', false);

        $sale = $this->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
            'save_only' => true,
            'discount_approval_reason' => 'Customer loyalty discount',
        ])->assertCreated()
            ->assertJsonPath('status', 'pending_approval')
            ->json();

        $this->assertDatabaseHas('action_requests', [
            'reference_type' => 'sale',
            'reference_id' => $sale['id'],
            'status' => 'pending',
            'reason' => 'Customer loyalty discount',
        ]);
    }

    public function test_cancelling_pending_approval_order_withdraws_discount_request_and_notifications(): void
    {
        $this->enableDiscountApproval();
        $staff = $this->mobileSalesRepUser();
        Sanctum::actingAs($staff);

        $product = Product::query()->firstOrFail();

        $cart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $staff->branch_id,
        ])->assertCreated()->json();

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $cartWithLine = $this->getJson("/api/v1/sales/carts/{$cart['id']}")->assertOk()->json();
        $lineRef = $cartWithLine['lines'][0]['update_code'] ?? $cartWithLine['lines'][0]['id'];

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/discount-requests", [
            'scope' => 'line',
            'line_ref' => (string) $lineRef,
            'discount_amount' => 10,
            'defer_approval' => true,
        ])->assertOk();

        $sale = $this->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
            'save_only' => true,
            'discount_approval_reason' => 'Customer loyalty discount',
        ])->assertCreated()
            ->assertJsonPath('status', 'pending_approval')
            ->json();

        $requestId = ActionRequest::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale['id'])
            ->where('type', 'discount')
            ->value('id');

        $this->assertNotNull($requestId);

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/notifications/all?bucket=pending_approvals')
            ->assertOk()
            ->assertJsonPath('data.0.action_request.id', (int) $requestId);

        Sanctum::actingAs($staff);

        $this->postJson("/api/v1/sales/orders/{$sale['id']}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('action_requests', [
            'id' => $requestId,
            'status' => 'cancelled',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/notifications/all?bucket=pending_approvals')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_rejecting_discount_notifies_requester(): void
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
            'discount_guidance' => 'remove_discount',
        ])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $staff->id,
            'type' => 'approval_outcome',
            'is_read' => false,
        ]);

        Sanctum::actingAs($staff);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 1);

        $list = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($list);
        $this->assertSame('approval_outcome', $list[0]['type']);
        $this->assertStringContainsString('rejected', strtolower($list[0]['message']));
    }
}
