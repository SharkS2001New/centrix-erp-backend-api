<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BranchStockTransferReportTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_report_catalog_hides_inter_branch_transfers_for_single_branch_org(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/v1/reports/')->assertOk()->json();
        $inventoryKeys = collect($res['inventory'] ?? [])->pluck('key')->all();

        $this->assertNotContains('branch-stock-transfers', $inventoryKeys);
    }

    public function test_report_catalog_lists_inter_branch_transfers_when_org_has_multiple_branches(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $org = Organization::findOrFail($user->organization_id);
        Branch::create([
            'organization_id' => $org->id,
            'branch_code' => 'TST2',
            'branch_name' => 'Test Branch Two',
            'branch_address' => 'Test address',
            'branch_phone' => '0700000001',
        ]);

        $res = $this->getJson('/api/v1/reports/')->assertOk()->json();
        $inventoryKeys = collect($res['inventory'] ?? [])->pluck('key')->all();

        $this->assertContains('branch-stock-transfers', $inventoryKeys);
    }

    public function test_branch_stock_transfers_report_endpoint_returns_rows(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $org = Organization::findOrFail($user->organization_id);
        $secondBranch = Branch::create([
            'organization_id' => $org->id,
            'branch_code' => 'TST3',
            'branch_name' => 'Test Branch Three',
            'branch_address' => 'Test address',
            'branch_phone' => '0700000002',
        ]);

        $fromBranch = Branch::query()->where('organization_id', $org->id)->orderBy('id')->firstOrFail();
        $product = \App\Models\Product::query()->firstOrFail();

        $this->postJson('/api/v1/inventory/branch-transfer', [
            'from_branch_id' => $fromBranch->id,
            'to_branch_id' => $secondBranch->id,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'from_location' => 'store',
            'to_location' => 'store',
        ])->assertCreated();

        $this->getJson('/api/v1/reports/branch-stock-transfers?per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }
}
