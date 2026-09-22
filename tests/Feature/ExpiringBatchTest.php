<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Damage;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ExpiringBatchTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    protected function setBatchTracking(bool $on): void
    {
        $org = Organization::query()->findOrFail((int) $this->user->organization_id);
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $inventory = is_array($settings['inventory'] ?? null) ? $settings['inventory'] : [];
        $inventory['enable_receive_batch_tracking'] = $on;
        $settings['inventory'] = $inventory;
        $org->module_settings = $settings;
        $org->save();
    }

    public function test_lists_expired_and_expiring_batches_and_clears_with_write_off(): void
    {
        $this->setBatchTracking(true);

        $product = Product::query()->whereNull('deleted_at')->firstOrFail();
        $orgId = (int) $this->user->organization_id;
        $branchId = (int) $this->user->branch_id;

        CurrentStock::query()->updateOrCreate(
            ['product_code' => $product->product_code, 'branch_id' => $branchId],
            ['shop_quantity' => 0, 'store_quantity' => 20],
        );

        $expired = StockReceipt::create([
            'product_code' => $product->product_code,
            'branch_id' => $branchId,
            'organization_id' => $orgId,
            'units_received' => 5,
            'stock_location' => 'store',
            'invoice_number' => 'GRN-EXP-1',
            'batch_no' => 'LOT-EXPIRED',
            'expiry_date' => Carbon::today()->subDays(3)->toDateString(),
            'cost_price' => 10,
            'received_by' => $this->user->id,
            'created_at' => now(),
        ]);

        StockReceipt::create([
            'product_code' => $product->product_code,
            'branch_id' => $branchId,
            'organization_id' => $orgId,
            'units_received' => 4,
            'stock_location' => 'store',
            'invoice_number' => 'GRN-EXP-2',
            'batch_no' => 'LOT-SOON',
            'expiry_date' => Carbon::today()->addDays(10)->toDateString(),
            'cost_price' => 12,
            'received_by' => $this->user->id,
            'created_at' => now(),
        ]);

        $list = $this->getJson('/api/v1/inventory/expiring-batches?status=open&within_days=30')
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(2, count($list));
        $codes = collect($list)->pluck('batch_no')->all();
        $this->assertContains('LOT-EXPIRED', $codes);
        $this->assertContains('LOT-SOON', $codes);

        $before = (float) CurrentStock::query()
            ->where('product_code', $product->product_code)
            ->where('branch_id', $branchId)
            ->value('store_quantity');

        $clear = $this->postJson("/api/v1/inventory/expiring-batches/{$expired->id}/clear", [
            'quantity' => 5,
        ])->assertOk()->json();

        $this->assertSame('cleared', $clear['receipt']['status'] ?? null);
        $this->assertNotNull($clear['damage']['id'] ?? null);

        $after = (float) CurrentStock::query()
            ->where('product_code', $product->product_code)
            ->where('branch_id', $branchId)
            ->value('store_quantity');

        $this->assertEqualsWithDelta($before - 5, $after, 0.001);
        $this->assertTrue(
            Damage::query()->whereKey($clear['damage']['id'])->exists(),
        );

        $expired->refresh();
        $this->assertNotNull($expired->expiry_cleared_at);
    }
}
