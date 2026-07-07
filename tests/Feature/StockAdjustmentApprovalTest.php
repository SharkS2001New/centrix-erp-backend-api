<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentApprovalService;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StockAdjustmentApprovalTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableAdjustmentApproval(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['inventory'] = array_merge($settings['inventory'] ?? [], [
            'stock_adjustment_approval_enabled' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function stockClerk(): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $roleId = (int) (DB::table('roles')->where('role_name', 'Stock Clerk')->value('id') ?? $admin->role_id);

        return User::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $roleId,
            'username' => 'stock_adj_clerk_'.uniqid(),
            'email' => null,
            'password' => $admin->password,
            'full_name' => 'Stock Adjustment Clerk',
            'is_admin' => false,
            'is_active' => true,
        ]);
    }

    protected function seedShopStock(User $user, string $productCode, float $qty = 20): void
    {
        CurrentStock::query()->updateOrCreate(
            ['product_code' => $productCode, 'branch_id' => $user->branch_id],
            ['shop_quantity' => $qty, 'store_quantity' => 10],
        );
    }

    public function test_manager_can_apply_stock_adjustment_from_action_request(): void
    {
        $this->enableAdjustmentApproval();
        $clerk = $this->stockClerk();
        $manager = User::where('username', 'admin')->firstOrFail();
        $product = Product::query()->firstOrFail();
        $this->seedShopStock($clerk, $product->product_code);

        $actionRequest = app(ActionRequestService::class)->requestApproval($clerk, [
            'type' => 'stock_adjustment',
            'module' => 'inventory',
            'reference_type' => 'stock_adjustment_request',
            'reference_id' => 0,
            'approver_permission' => 'inventory.manage',
            'title' => 'Stock adjustment approval required',
            'message' => 'Clerk requested +2 adjustment.',
            'reason' => 'Cycle count variance',
            'severity' => 'warning',
            'action_url' => '/inventory/adjustments',
            'allow_duplicate_reference' => true,
            'payload' => [
                'branch_id' => $clerk->branch_id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'stock_location' => 'shop',
                'quantity_change' => 2,
                'notes' => 'Cycle count variance',
                'action_url' => '/inventory/adjustments',
            ],
        ]);

        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/action-requests/{$actionRequest->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('inventory_transactions', [
            'product_code' => $product->product_code,
            'branch_id' => $clerk->branch_id,
            'transaction_type' => 'ADJUSTMENT',
        ]);

        $this->assertDatabaseHas('action_requests', [
            'id' => $actionRequest->id,
            'status' => 'approved',
        ]);
    }

    public function test_adjust_request_endpoint_creates_pending_action_request(): void
    {
        $this->enableAdjustmentApproval();
        $clerk = $this->stockClerk();
        Sanctum::actingAs($clerk);
        $product = Product::query()->firstOrFail();
        $this->seedShopStock($clerk, $product->product_code);

        $this->assertFalse(app(StockAdjustmentApprovalService::class)->canDirectAdjust($clerk));

        $this->postJson('/api/v1/inventory/adjust/request', [
            'branch_id' => $clerk->branch_id,
            'product_code' => $product->product_code,
            'stock_location' => 'shop',
            'quantity_change' => -1,
            'notes' => 'Shrinkage',
        ])
            ->assertAccepted()
            ->assertJsonPath('pending_approval', true);

        $this->assertDatabaseHas('action_requests', [
            'organization_id' => $clerk->organization_id,
            'type' => 'stock_adjustment',
            'status' => 'pending',
            'requested_by' => $clerk->id,
        ]);
    }
}
