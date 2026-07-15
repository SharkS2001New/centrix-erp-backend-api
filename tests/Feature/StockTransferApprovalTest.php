<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\StockTransferService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StockTransferApprovalTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableTransferApproval(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['inventory'] = array_merge($settings['inventory'] ?? [], [
            'stock_transfer_approval_enabled' => true,
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
            'username' => 'stock_clerk_'.uniqid(),
            'email' => null,
            'password' => $admin->password,
            'full_name' => 'Stock Clerk',
            'is_admin' => false,
            'is_active' => true,
        ]);
    }

    protected function seedStoreStock(User $user, string $productCode, float $qty = 50): void
    {
        CurrentStock::query()->updateOrCreate(
            ['product_code' => $productCode, 'branch_id' => $user->branch_id],
            ['shop_quantity' => 5, 'store_quantity' => $qty],
        );
    }

    public function test_store_to_shop_transfer_completes_without_approval(): void
    {
        $this->enableTransferApproval();
        $clerk = $this->stockClerk();
        $product = Product::query()->firstOrFail();
        $this->seedStoreStock($clerk, $product->product_code);

        app(StockTransferService::class)->transfer(
            (int) $clerk->branch_id,
            (string) $product->product_code,
            3.0,
            'store',
            'shop',
            $clerk,
        );

        $this->assertDatabaseHas('stock_movement_history', [
            'product_code' => $product->product_code,
            'branch_id' => $clerk->branch_id,
            'from_location' => 'store',
            'to_location' => 'shop',
        ]);
    }

    public function test_shop_to_store_requires_approval_request_for_non_manager(): void
    {
        $this->enableTransferApproval();
        $clerk = $this->stockClerk();
        Sanctum::actingAs($clerk);
        $product = Product::query()->firstOrFail();
        $this->seedStoreStock($clerk, $product->product_code, 10);

        $this->assertFalse(app(\App\Services\Inventory\StockTransferApprovalService::class)->canDirectTransfer($clerk));

        $this->postJson('/api/v1/inventory/transfer', [
            'branch_id' => $clerk->branch_id,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'from_location' => 'shop',
            'to_location' => 'store',
        ])->assertStatus(422);

        $this->postJson('/api/v1/inventory/transfer/request', [
            'branch_id' => $clerk->branch_id,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'from_location' => 'shop',
            'to_location' => 'store',
            'notes' => 'Return excess to warehouse',
        ])
            ->assertAccepted()
            ->assertJsonPath('pending_approval', true);

        $this->assertDatabaseHas('action_requests', [
            'organization_id' => $clerk->organization_id,
            'type' => 'stock_transfer',
            'status' => 'pending',
            'requested_by' => $clerk->id,
        ]);

        $this->assertDatabaseMissing('stock_movement_history', [
            'product_code' => $product->product_code,
            'from_location' => 'shop',
            'to_location' => 'store',
        ]);
    }

    public function test_internal_use_purpose_transfer_deducts_from_source_only(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $product = Product::query()->firstOrFail();
        $this->seedStoreStock($admin, $product->product_code, 40);

        $before = CurrentStock::query()
            ->where('product_code', $product->product_code)
            ->where('branch_id', $admin->branch_id)
            ->firstOrFail();

        $result = app(StockTransferService::class)->transfer(
            (int) $admin->branch_id,
            (string) $product->product_code,
            4.0,
            'store',
            'internal_use',
            $admin,
            'Staff lunch',
        );

        $this->assertNotNull($result['out']);
        $this->assertNull($result['in']);

        $after = CurrentStock::query()
            ->where('product_code', $product->product_code)
            ->where('branch_id', $admin->branch_id)
            ->firstOrFail();
        $this->assertEqualsWithDelta((float) $before->store_quantity - 4, (float) $after->store_quantity, 0.001);
        $this->assertEqualsWithDelta((float) $before->shop_quantity, (float) $after->shop_quantity, 0.001);
    }
}
