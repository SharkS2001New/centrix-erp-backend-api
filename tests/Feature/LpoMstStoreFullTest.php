<?php

namespace Tests\Feature;

use App\Models\LpoMst;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LpoMstStoreFullTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_store_full_sets_organization_id_and_lpo_seq(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::firstOrFail();

        $response = $this->postJson('/api/v1/lpo-mst/full', [
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'ordered_qty' => 2,
                    'cost_price' => 100,
                    'uom' => 'kg',
                ],
            ],
        ]);

        $response->assertCreated();
        $lpoNo = (int) $response->json('lpo_no');
        $this->assertGreaterThan(0, $lpoNo);

        $lpo = LpoMst::query()->findOrFail($lpoNo);
        $this->assertSame((int) $admin->organization_id, (int) $lpo->organization_id);
        $this->assertGreaterThan(0, (int) $lpo->lpo_seq);
        $this->assertSame($supplier->id, (int) $lpo->supplier_id);
    }
}
