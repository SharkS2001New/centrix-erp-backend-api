<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\LpoMst;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SupplierReturnDocumentTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_user_can_create_and_list_supplier_return_document(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $productCode = Product::first()->product_code;

        $response = $this->postJson('/api/v1/supplier-return-documents', [
            'supplier_id' => $supplier->id,
            'branch_id' => $admin->branch_id,
            'source_type' => 'manual',
            'reason_scope' => 'order',
            'return_reason' => 'Damaged packaging on delivery',
            'notes' => 'Damaged packaging on delivery',
            'lines' => [
                [
                    'product_code' => $productCode,
                    'quantity' => 2,
                    'package_type' => 'pieces',
                    'stock_location' => 'store',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.supplier_id', $supplier->id);

        $this->getJson('/api/v1/supplier-return-documents?supplier_id='.$supplier->id)
            ->assertOk()
            ->assertJsonFragment(['status' => 'pending_approval']);
    }

    public function test_admin_can_approve_return_and_deduct_stock(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::firstOrFail();
        $productCode = $product->product_code;

        DB::table('current_stock')->updateOrInsert(
            ['product_code' => $productCode, 'branch_id' => $admin->branch_id],
            ['shop_quantity' => 0, 'store_quantity' => 20],
        );

        $docId = $this->postJson('/api/v1/supplier-return-documents', [
            'supplier_id' => $supplier->id,
            'branch_id' => $admin->branch_id,
            'source_type' => 'manual',
            'reason_scope' => 'order',
            'return_reason' => 'Supplier sent wrong batch',
            'lines' => [
                [
                    'product_code' => $productCode,
                    'quantity' => 5,
                    'package_type' => 'pieces',
                    'stock_location' => 'store',
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/supplier-return-documents/{$docId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $stock = CurrentStock::where('product_code', $productCode)
            ->where('branch_id', $admin->branch_id)
            ->first();

        $this->assertEquals(15, (float) $stock->store_quantity);

        $this->assertDatabaseHas('inventory_transactions', [
            'product_code' => $productCode,
            'transaction_type' => 'SUPPLIER_RETURN',
            'reference_type' => 'supplier_return_document',
            'reference_id' => $docId,
        ]);
    }

    public function test_lpo_return_validates_against_received_quantity(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $lpo = LpoMst::create([
            'supplier_id' => $supplier->id,
            'reference_number' => 'PO-RET-TEST',
            'total_amount' => 1000,
            'net_amount' => 1000,
            'created_by' => $admin->id,
            'created_at' => now(),
            'lpo_status_code' => 4,
        ]);

        LpoTxn::create([
            'lpo_no' => $lpo->lpo_no,
            'product_code' => Product::first()->product_code,
            'ordered_qty' => 10,
            'received_qty' => 10,
            'cost_price' => 100,
            'uom' => 'kg',
        ]);

        $this->postJson('/api/v1/supplier-return-documents', [
            'supplier_id' => $supplier->id,
            'branch_id' => $admin->branch_id,
            'source_type' => 'lpo',
            'lpo_no' => $lpo->lpo_no,
            'reason_scope' => 'order',
            'return_reason' => 'Quality issue on received goods',
            'lines' => [
                [
                    'product_code' => Product::first()->product_code,
                    'quantity' => 15,
                    'package_type' => 'pieces',
                    'stock_location' => 'store',
                ],
            ],
        ])->assertStatus(422);
    }
}
