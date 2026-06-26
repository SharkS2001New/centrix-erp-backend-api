<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\InventoryTransaction;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LegacyReturnTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_legacy_return_requires_kra_device(): void
    {
        $sale = $this->createLegacySale();

        $this->postJson('/api/v1/legacy-returns', [
            'sale_id' => $sale->id,
            'kra_original_invoice_number' => '00009999',
            'reason' => 'Refund',
            'lines' => [
                [
                    'product_code' => $sale->items->first()->product_code,
                    'quantity_sold' => 2,
                    'return_qty' => 1,
                    'unit_price' => 100,
                    'amount' => 100,
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['kra']);
    }

    public function test_legacy_return_does_not_post_stock_and_issues_credit_note(): void
    {
        $this->enableKraDevice();
        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => true,
                'message' => 'OK',
                'invoice_number' => 'CN-LEG-1',
                'cu-inv-no' => '00008888',
                'Receipt Signature' => 'SIG-LEGACY',
                'signature_link' => 'https://example.test/legacy-credit',
                'serial_number' => 'DEJA02220240050',
                'timestamp' => '2026-06-20T10:00:00',
            ], 200),
        ]);

        $sale = $this->createLegacySale();
        $beforeStock = InventoryTransaction::query()->count();

        $created = $this->postJson('/api/v1/legacy-returns', [
            'sale_id' => $sale->id,
            'kra_original_invoice_number' => '00007777',
            'reason' => 'Damaged Product',
            'full_return' => true,
        ])->assertCreated()
            ->assertJsonPath('return_kind', 'legacy')
            ->assertJsonPath('return_no', fn ($v) => str_starts_with((string) $v, 'LRET-'))
            ->assertJsonPath('total_amount', 200);

        $returnId = $created->json('id');

        $this->assertSame($beforeStock, InventoryTransaction::query()->count());

        $this->assertDatabaseHas('credit_notes', [
            'customer_return_id' => $returnId,
            'sale_id' => $sale->id,
            'total_amount' => 200,
            'kra_status' => 'success',
            'kra_relevant_invoice_number' => '00007777',
        ]);

        $sale->refresh();
        $this->assertSame(0.0, (float) $sale->order_total);
    }

    public function test_legacy_return_rejects_when_kra_device_fails(): void
    {
        $this->enableKraDevice();
        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => false,
                'message' => 'Invalid relevant invoice number',
            ], 200),
        ]);

        $sale = $this->createLegacySale();
        $beforeReturns = \App\Models\CustomerReturn::query()->count();

        $this->postJson('/api/v1/legacy-returns', [
            'sale_id' => $sale->id,
            'kra_original_invoice_number' => '00007777',
            'reason' => 'Damaged Product',
            'full_return' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['kra']);

        $this->assertSame($beforeReturns, \App\Models\CustomerReturn::query()->count());
        $this->assertDatabaseMissing('credit_notes', [
            'sale_id' => $sale->id,
        ]);
    }

    public function test_legacy_return_lines_prefill_full_order_amounts(): void
    {
        $sale = $this->createLegacySale();

        $res = $this->getJson("/api/v1/legacy-orders/{$sale->id}/return-lines")
            ->assertOk();

        $line = collect($res->json('lines'))->first();
        $this->assertSame(2.0, (float) $line['return_qty']);
        $this->assertSame(200.0, (float) $line['amount']);
        $this->assertSame(200.0, (float) $line['line_total']);
        $this->assertTrue($line['full_return']);
    }

    public function test_legacy_orders_list_excludes_normal_sales(): void
    {
        $legacy = $this->createLegacySale();
        $normal = Sale::query()->firstOrFail();
        $normal->update(['fulfillment_meta' => null]);

        $res = $this->getJson('/api/v1/legacy-orders')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($legacy->id, $ids);
        $this->assertNotContains($normal->id, $ids);
    }

    public function test_normal_sales_list_excludes_legacy_materialized_orders(): void
    {
        $legacy = $this->createLegacySale();

        $res = $this->getJson('/api/v1/sales?per_page=200')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertNotContains($legacy->id, $ids);
    }

    public function test_reports_dashboard_excludes_legacy_materialized_sales(): void
    {
        $sale = Sale::query()->firstOrFail();
        $sale->update([
            'status' => 'completed',
            'order_total' => 5000,
            'amount_paid' => 5000,
            'payment_status' => 'paid',
            'archived' => 0,
            'completed_at' => now(),
            'fulfillment_meta' => null,
        ]);

        $params = [
            'from_date' => now()->toDateString(),
            'to_date' => now()->toDateString(),
        ];

        $before = (float) $this->getJson('/api/v1/reports/dashboard?' . http_build_query($params))
            ->assertOk()
            ->json('kpis.total_sales.value');

        $sale->update([
            'fulfillment_meta' => [
                'legacy_import' => true,
                'legacy_order_num' => 1000099,
                'legacy_order_label' => 'POS-1000099',
                'legacy_sale_date' => now()->toDateString(),
                'legacy_source' => 'pos_masters',
            ],
        ]);

        $after = (float) $this->getJson('/api/v1/reports/dashboard?' . http_build_query($params))
            ->assertOk()
            ->json('kpis.total_sales.value');

        $this->assertEqualsWithDelta($before - 5000, $after, 0.01);
    }

    protected function createLegacySale(): Sale
    {
        $product = Product::firstOrFail();
        $sale = Sale::query()->firstOrFail();
        $sale->update([
            'status' => 'completed',
            'order_total' => 200,
            'amount_paid' => 200,
            'payment_status' => 'paid',
            'fulfillment_meta' => [
                'legacy_import' => true,
                'legacy_order_num' => 1000042,
                'legacy_order_label' => 'POS-1000042',
                'legacy_sale_date' => '2026-06-01',
                'legacy_source' => 'pos_masters',
            ],
        ]);

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

        return $sale->fresh(['items']);
    }

    protected function enableKraDevice(): void
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
    }
}
