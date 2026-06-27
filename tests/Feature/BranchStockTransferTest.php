<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStockTransfer;
use App\Models\CurrentStock;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BranchStockTransferTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_branch_stock_transfer_moves_quantity_between_branches(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $branches = Branch::query()
            ->where('organization_id', $user->organization_id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($branches->count() < 2) {
            $this->markTestSkipped('Need at least two branches in demo data.');
        }

        $fromBranch = $branches[0];
        $toBranch = $branches[1];
        $product = Product::query()->firstOrFail();
        $productCode = $product->product_code;

        CurrentStock::updateOrCreate(
            ['product_code' => $productCode, 'branch_id' => $fromBranch->id],
            ['shop_quantity' => 0, 'store_quantity' => 50],
        );
        CurrentStock::updateOrCreate(
            ['product_code' => $productCode, 'branch_id' => $toBranch->id],
            ['shop_quantity' => 0, 'store_quantity' => 10],
        );

        $beforeFrom = (float) CurrentStock::where('product_code', $productCode)
            ->where('branch_id', $fromBranch->id)
            ->value('store_quantity');
        $beforeTo = (float) CurrentStock::where('product_code', $productCode)
            ->where('branch_id', $toBranch->id)
            ->value('store_quantity');

        $this->postJson('/api/v1/inventory/branch-transfer', [
            'from_branch_id' => $fromBranch->id,
            'to_branch_id' => $toBranch->id,
            'product_code' => $productCode,
            'quantity' => 5,
            'from_location' => 'store',
            'to_location' => 'store',
            'notes' => 'Test inter-branch move',
        ])->assertCreated();

        $this->assertEquals($beforeFrom - 5, (float) CurrentStock::where('product_code', $productCode)
            ->where('branch_id', $fromBranch->id)
            ->value('store_quantity'));
        $this->assertEquals($beforeTo + 5, (float) CurrentStock::where('product_code', $productCode)
            ->where('branch_id', $toBranch->id)
            ->value('store_quantity'));

        $this->assertDatabaseHas('branch_stock_transfers', [
            'from_branch_id' => $fromBranch->id,
            'to_branch_id' => $toBranch->id,
            'product_code' => $productCode,
            'quantity' => 5,
        ]);

        $record = BranchStockTransfer::query()->latest('id')->first();
        $this->assertNotNull($record);
        $this->assertDatabaseHas('inventory_transactions', [
            'branch_id' => $fromBranch->id,
            'reference_type' => 'branch_transfer',
            'reference_id' => $record->id,
        ]);
    }
}
