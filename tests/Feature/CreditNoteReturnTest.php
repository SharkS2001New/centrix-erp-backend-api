<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\KraResponse;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CreditNoteReturnTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_approve_return_creates_credit_note(): void
    {
        $product = Product::firstOrFail();
        $sale = Sale::query()->firstOrFail();
        $sale->update(['status' => 'completed']);

        SaleItem::query()->updateOrInsert(
            ['sale_id' => $sale->id, 'product_code' => $product->product_code],
            [
                'line_no' => 1,
                'quantity' => 2,
                'selling_price' => 100,
                'amount' => 200,
                'product_vat' => 0,
                'discount_given' => 0,
            ],
        );
        $sale->update(['order_total' => 200, 'amount_paid' => 200, 'payment_status' => 'paid']);

        $created = $this->postJson('/api/v1/customer-returns', [
            'sale_id' => $sale->id,
            'reason' => 'Damaged Product',
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'quantity_sold' => 2,
                    'return_qty' => 1,
                    'unit_price' => 100,
                    'amount' => 100,
                ],
            ],
        ])->assertCreated();

        $returnId = $created->json('id');

        $this->postJson("/api/v1/customer-returns/{$returnId}/approve")
            ->assertOk()
            ->assertJsonPath('credit_note.credit_note_no', fn ($v) => str_starts_with((string) $v, 'CN-'));

        $this->assertDatabaseHas('credit_notes', [
            'customer_return_id' => $returnId,
            'sale_id' => $sale->id,
            'organization_id' => $this->user->organization_id,
            'branch_id' => $sale->branch_id ?? $this->user->branch_id,
            'total_amount' => 100,
            'kra_status' => 'skipped',
        ]);
    }

    public function test_credit_note_numbers_restart_per_organization(): void
    {
        $service = app(\App\Services\Sales\CreditNoteService::class);
        $orgA = Organization::create([
            'company_code' => 'CNORGA',
            'org_name' => 'CN Org A',
            'org_email' => 'cnorga@test.com',
            'primary_tel' => '0700222001',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);
        $orgB = Organization::create([
            'company_code' => 'CNORGB',
            'org_name' => 'CN Org B',
            'org_email' => 'cnorgb@test.com',
            'primary_tel' => '0700222002',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);

        $returnId = \Illuminate\Support\Facades\DB::table('customer_returns')->insertGetId([
            'return_no' => 'RET-CN-A1',
            'return_seq' => 1,
            'organization_id' => $orgA->id,
            'branch_id' => $this->user->branch_id,
            'return_date' => now()->toDateString(),
            'status' => 'approved',
            'total_amount' => 10,
            'returned_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('credit_notes')->insert([
            'credit_note_no' => 'CN-0001',
            'customer_return_id' => $returnId,
            'organization_id' => $orgA->id,
            'branch_id' => $this->user->branch_id,
            'credit_date' => now()->toDateString(),
            'total_amount' => 10,
            'kra_status' => 'skipped',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('CN-0001', $service->nextCreditNoteNo((int) $orgB->id));
        $this->assertSame('CN-0002', $service->nextCreditNoteNo((int) $orgA->id));
    }

    public function test_approve_return_submits_kra_credit_note_when_original_sale_fiscalized(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_kra_device' => true,
            'kra_device_ip' => 'http://192.168.1.50:8010',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
        ]);
        $org->update(['module_settings' => $settings]);

        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => true,
                'message' => 'OK',
                'invoice_number' => 'CN-CU-99',
                'cu-inv-no' => '00001234',
                'Receipt Signature' => 'SIG-CREDIT',
                'signature_link' => 'https://example.test/credit-qr',
                'serial_number' => 'DEJA02220240050',
                'timestamp' => '2026-06-11T14:00:00',
            ], 200),
        ]);

        $product = Product::firstOrFail();
        $sale = Sale::query()->firstOrFail();
        $sale->update(['status' => 'completed', 'order_total' => 200, 'amount_paid' => 200, 'payment_status' => 'paid']);

        SaleItem::query()->updateOrInsert(
            ['sale_id' => $sale->id, 'product_code' => $product->product_code],
            [
                'line_no' => 1,
                'quantity' => 2,
                'selling_price' => 100,
                'amount' => 200,
                'product_vat' => 0,
                'discount_given' => 0,
            ],
        );

        KraResponse::create([
            'sale_id' => $sale->id,
            'organization_id' => (int) $sale->organization_id,
            'order_no' => $sale->order_num ?? 90001,
            'invoice_number' => 'CU-ORIG-1',
            'receipt_signature' => 'SIG-ORIG',
            'status' => 'success',
            'response_payload' => [
                'cu_inv_no' => '00005678',
                'invoice_number' => 'CU-ORIG-1',
            ],
        ]);

        $created = $this->postJson('/api/v1/customer-returns', [
            'sale_id' => $sale->id,
            'reason' => 'Damaged Product',
            'refund_method' => 'CASH',
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'quantity_sold' => 2,
                    'return_qty' => 1,
                    'unit_price' => 100,
                    'amount' => 100,
                ],
            ],
        ])->assertCreated();

        $returnId = $created->json('id');

        $this->postJson("/api/v1/customer-returns/{$returnId}/approve")
            ->assertOk()
            ->assertJsonPath('credit_note.kra_status', 'success');

        $creditNote = CreditNote::query()->where('customer_return_id', $returnId)->firstOrFail();
        $this->assertSame('03', $creditNote->kra_refund_reason_code);
        $this->assertSame('5678', $creditNote->kra_relevant_invoice_number);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/complete-workflow')) {
                return false;
            }

            $body = $request->data();
            $sign = $body['sign_structure'] ?? [];

            return ($sign['InvoiceType'] ?? '') === 'credit'
                && ($sign['relevantInvoiceNumber'] ?? '') === '5678'
                && ($sign['rfdRsnCd'] ?? '') === '03'
                && ($sign['CashAmt'] ?? '') === '100.00'
                && ($sign['CardAmt'] ?? '') === '0.00'
                && (($body['plu_data'][0]['Barcode'] ?? null) === '');
        });
    }

    public function test_approve_return_rolls_back_when_kra_device_fails(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_kra_device' => true,
            'kra_device_ip' => 'http://192.168.1.50:8010',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
        ]);
        $org->update(['module_settings' => $settings]);

        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => false,
                'message' => 'Credit note rejected by device',
            ], 200),
        ]);

        $product = Product::firstOrFail();
        $sale = Sale::query()->firstOrFail();
        $sale->update(['status' => 'completed', 'order_total' => 200, 'amount_paid' => 200, 'payment_status' => 'paid']);

        SaleItem::query()->updateOrInsert(
            ['sale_id' => $sale->id, 'product_code' => $product->product_code],
            [
                'line_no' => 1,
                'quantity' => 2,
                'selling_price' => 100,
                'amount' => 200,
                'product_vat' => 0,
                'discount_given' => 0,
            ],
        );

        KraResponse::create([
            'sale_id' => $sale->id,
            'organization_id' => (int) $sale->organization_id,
            'order_no' => $sale->order_num ?? 90001,
            'invoice_number' => 'CU-ORIG-2',
            'status' => 'success',
            'response_payload' => [
                'cu_inv_no' => '00009999',
                'invoice_number' => 'CU-ORIG-2',
            ],
        ]);

        $created = $this->postJson('/api/v1/customer-returns', [
            'sale_id' => $sale->id,
            'reason' => 'Damaged Product',
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'quantity_sold' => 2,
                    'return_qty' => 1,
                    'unit_price' => 100,
                    'amount' => 100,
                ],
            ],
        ])->assertCreated();

        $returnId = $created->json('id');

        $this->postJson("/api/v1/customer-returns/{$returnId}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kra']);

        $this->assertDatabaseHas('customer_returns', [
            'id' => $returnId,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('credit_notes', [
            'customer_return_id' => $returnId,
        ]);
    }
}
