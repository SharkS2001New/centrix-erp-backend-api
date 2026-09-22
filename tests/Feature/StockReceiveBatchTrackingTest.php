<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StockReceiveBatchTrackingTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_batch_fields_ignored_when_platform_flag_disabled(): void
    {
        $this->setBatchTracking(false);

        $product = Product::query()->whereNull('deleted_at')->firstOrFail();

        $res = $this->postJson('/api/v1/inventory/receive', [
            'product_code' => $product->product_code,
            'branch_id' => $this->user->branch_id,
            'units_received' => 2,
            'cost_price' => 10,
            'stock_location' => 'store',
            'invoice_number' => 'GRN-BATCH-OFF',
            'batch_no' => 'LOT-SHOULD-IGNORE',
            'expiry_date' => '2027-01-15',
        ])->assertCreated()->json();

        $row = StockReceipt::query()->findOrFail($res['id']);
        $this->assertNull($row->batch_no);
        $this->assertNull($row->expiry_date);
    }

    public function test_batch_and_expiry_saved_when_platform_flag_enabled(): void
    {
        $this->setBatchTracking(true);

        $product = Product::query()->whereNull('deleted_at')->firstOrFail();

        $res = $this->postJson('/api/v1/inventory/receive', [
            'product_code' => $product->product_code,
            'branch_id' => $this->user->branch_id,
            'units_received' => 4,
            'cost_price' => 12.5,
            'stock_location' => 'store',
            'invoice_number' => 'GRN-BATCH-ON',
            'batch_no' => 'LOT-2026-A1',
            'expiry_date' => '2027-06-30',
        ])->assertCreated()->json();

        $this->assertSame('LOT-2026-A1', $res['batch_no']);
        $this->assertSame('2027-06-30', substr((string) $res['expiry_date'], 0, 10));

        $this->assertDatabaseHas('stock_receipts', [
            'id' => $res['id'],
            'batch_no' => 'LOT-2026-A1',
        ]);
    }

    public function test_receipts_searchable_by_batch_number(): void
    {
        $this->setBatchTracking(true);

        $product = Product::query()->whereNull('deleted_at')->firstOrFail();

        $this->postJson('/api/v1/inventory/receive', [
            'product_code' => $product->product_code,
            'branch_id' => $this->user->branch_id,
            'units_received' => 1,
            'stock_location' => 'store',
            'invoice_number' => 'GRN-SEARCH-1',
            'batch_no' => 'RECALL-BATCH-99',
            'expiry_date' => '2028-01-01',
        ])->assertCreated();

        $list = $this->getJson('/api/v1/stock-receipts?q=RECALL-BATCH-99')
            ->assertOk()
            ->json();

        $items = $list['data'] ?? $list;
        $this->assertNotEmpty($items);
        $this->assertTrue(
            collect($items)->contains(fn ($row) => ($row['batch_no'] ?? null) === 'RECALL-BATCH-99'),
        );
    }

    public function test_platform_admin_can_enable_receive_batch_tracking(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $orgId = (int) $this->user->organization_id;

        $this->patchJson("/api/v1/admin/organizations/{$orgId}", [
            'sales_platform' => [
                'enable_receive_batch_tracking' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('sales_platform.enable_receive_batch_tracking', true);

        $org = Organization::findOrFail($orgId);
        $this->assertTrue((bool) ($org->module_settings['inventory']['enable_receive_batch_tracking'] ?? false));
    }

    protected function setBatchTracking(bool $enabled): void
    {
        $org = Organization::findOrFail((int) $this->user->organization_id);
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $inventory = is_array($settings['inventory'] ?? null) ? $settings['inventory'] : [];
        $inventory['enable_receive_batch_tracking'] = $enabled;
        $settings['inventory'] = $inventory;
        $org->forceFill(['module_settings' => $settings])->save();
        app(\App\Services\Erp\ErpContext::class)->forgetOrganizationCache((int) $org->id);
    }
}
