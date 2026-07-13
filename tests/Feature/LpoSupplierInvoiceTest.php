<?php

namespace Tests\Feature;

use App\Models\LpoMst;
use App\Models\LpoSupplierInvoice;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LpoSupplierInvoiceTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
        Storage::fake('public');
    }

    protected function createLpo(Supplier $supplier): LpoMst
    {
        $orgId = (int) $this->user->organization_id;
        $nextSeq = (int) LpoMst::query()->where('organization_id', $orgId)->max('lpo_seq') + 1;

        return LpoMst::create([
            'organization_id' => $orgId,
            'lpo_seq' => $nextSeq,
            'supplier_id' => $supplier->id,
            'reference_number' => 'PO-TEST-'.$nextSeq,
            'total_amount' => 1000,
            'net_amount' => 1000,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'lpo_status_code' => 1,
        ]);
    }

    public function test_user_can_upload_supplier_invoice_document(): void
    {
        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $lpo = $this->createLpo($supplier);

        $file = UploadedFile::fake()->create('supplier-invoice.pdf', 120, 'application/pdf');

        $response = $this->post('/api/v1/lpo-supplier-invoices', [
            'lpo_no' => $lpo->lpo_no,
            'supplier_id' => $supplier->id,
            'supplier_invoice_number' => 'INV-2026-001',
            'invoice_date' => '2026-07-13',
            'file' => $file,
        ]);

        $response->assertCreated()
            ->assertJsonPath('supplier_invoice_number', 'INV-2026-001');

        $invoiceId = (int) $response->json('id');
        $invoice = LpoSupplierInvoice::findOrFail($invoiceId);
        $this->assertNotNull($invoice->file_path);
        Storage::disk('public')->assertExists($invoice->file_path);

        $this->get("/api/v1/lpo-supplier-invoices/{$invoiceId}/file")
            ->assertOk();
    }

    public function test_lpo_receive_allows_quantity_over_ordered_for_offers(): void
    {
        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::firstOrFail();
        $lpo = $this->createLpo($supplier);

        $txn = LpoTxn::create([
            'lpo_no' => $lpo->lpo_no,
            'product_code' => $product->product_code,
            'ordered_qty' => 10,
            'received_qty' => 0,
            'cost_price' => 100,
            'uom' => 'kg',
        ]);

        $this->postJson('/api/v1/inventory/receive', [
            'product_code' => $product->product_code,
            'branch_id' => $this->user->branch_id,
            'units_received' => 15,
            'cost_price' => 100,
            'stock_location' => 'store',
            'invoice_number' => 'GRN-OFFER-001',
            'lpo_no' => $lpo->lpo_no,
            'lpo_txn_id' => $txn->id,
        ])->assertCreated();

        $txn->refresh();
        $this->assertSame(15.0, (float) $txn->received_qty);
        $this->assertSame(5.0, (float) $txn->offer_qty);
    }
}
