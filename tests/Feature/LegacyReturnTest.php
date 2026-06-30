<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\CustomerReturn;
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
        $this->enableLegacyArchive();
        Sanctum::actingAs($this->user);
    }

    protected function enableLegacyArchive(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['legacy_archive'] = array_merge($settings['legacy_archive'] ?? [], ['enabled' => true]);
        $org->update(['module_settings' => $settings]);
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

        $this->assertDatabaseHas('kra_responses', [
            'sale_id' => $sale->id,
            'organization_id' => $sale->organization_id,
            'status' => 'success',
        ]);

        $sale->refresh();
        $this->assertSame(0.0, (float) $sale->order_total);
    }

    public function test_legacy_orders_list_marks_fully_returned_after_full_return(): void
    {
        $this->enableKraDevice();
        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => true,
                'message' => 'OK',
                'invoice_number' => 'CN-LEG-FULL',
                'cu-inv-no' => '00008889',
                'Receipt Signature' => 'SIG-LEGACY-FULL',
                'signature_link' => 'https://example.test/legacy-credit-full',
                'serial_number' => 'DEJA02220240050',
                'timestamp' => '2026-06-20T10:00:00',
            ], 200),
        ]);

        $sale = $this->createLegacySale();

        $this->postJson('/api/v1/legacy-returns', [
            'sale_id' => $sale->id,
            'kra_original_invoice_number' => '00007778',
            'reason' => 'Full refund',
            'full_return' => true,
        ])->assertCreated();

        $item = collect($this->getJson('/api/v1/legacy-orders')->assertOk()->json('data'))
            ->firstWhere('id', $sale->id);

        $this->assertNotNull($item);
        $this->assertTrue($item['legacy_return_summary']['fully_returned']);
        $this->assertTrue($item['legacy_return_summary']['has_returns']);
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

    public function test_legacy_order_can_be_deleted_when_it_has_no_returns(): void
    {
        $sale = $this->createLegacySale();

        $this->deleteJson("/api/v1/legacy-orders/{$sale->id}")
            ->assertOk();

        $this->assertDatabaseMissing('sales', ['id' => $sale->id]);
        $this->assertDatabaseMissing('sale_items', ['sale_id' => $sale->id]);
    }

    public function test_non_admin_cannot_delete_legacy_order_when_returns_exist(): void
    {
        $sale = $this->createLegacySale();
        $manager = User::where('username', 'admin')->firstOrFail();
        $manager->update(['is_admin' => false]);

        CustomerReturn::query()->create([
            'return_no' => 'LRET-DEL-BLOCK',
            'organization_id' => $sale->organization_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'return_date' => '2026-06-01',
            'status' => 'pending',
            'return_kind' => 'legacy',
            'total_amount' => 0,
            'returned_by' => $manager->id,
        ]);

        Sanctum::actingAs($manager);

        $this->deleteJson("/api/v1/legacy-orders/{$sale->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sale']);

        $this->assertDatabaseHas('sales', ['id' => $sale->id]);

        $manager->update(['is_admin' => true]);
    }

    public function test_admin_can_delete_legacy_order_with_pending_or_approved_returns(): void
    {
        $sale = $this->createLegacySale();

        $pending = CustomerReturn::query()->create([
            'return_no' => 'LRET-DEL-PEND',
            'organization_id' => $sale->organization_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'return_date' => '2026-06-01',
            'status' => 'pending',
            'return_kind' => 'legacy',
            'total_amount' => 50,
            'returned_by' => $this->user->id,
        ]);

        $approved = CustomerReturn::query()->create([
            'return_no' => 'LRET-DEL-APPR',
            'organization_id' => $sale->organization_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'return_date' => '2026-06-02',
            'status' => 'approved',
            'return_kind' => 'legacy',
            'total_amount' => 100,
            'returned_by' => $this->user->id,
            'approved_by' => $this->user->id,
            'approved_at' => now(),
        ]);

        CreditNote::query()->create([
            'credit_note_no' => 'CN-DEL-LEG',
            'customer_return_id' => $approved->id,
            'organization_id' => $sale->organization_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'credit_date' => '2026-06-02',
            'total_amount' => 100,
            'refund_method' => 'cash',
            'reason' => 'Legacy return',
        ]);

        $this->deleteJson("/api/v1/legacy-orders/{$sale->id}")
            ->assertOk();

        $this->assertDatabaseMissing('sales', ['id' => $sale->id]);
        $this->assertDatabaseMissing('customer_returns', ['id' => $pending->id]);
        $this->assertDatabaseMissing('customer_returns', ['id' => $approved->id]);
        $this->assertDatabaseMissing('credit_notes', ['customer_return_id' => $approved->id]);
    }

    public function test_legacy_orders_can_filter_by_order_total(): void
    {
        $sale = $this->createLegacySale();
        $sale->update(['order_total' => 12500]);

        $other = Sale::query()
            ->where('organization_id', $sale->organization_id)
            ->where('id', '!=', $sale->id)
            ->firstOrFail();
        $other->update([
            'fulfillment_meta' => [
                'legacy_import' => true,
                'legacy_order_num' => 1000099,
                'legacy_order_label' => 'POS-1000099',
                'legacy_sale_date' => '2026-06-01',
                'legacy_source' => 'pos_masters',
            ],
            'order_total' => 45000,
        ]);

        $ids = fn (array $query) => collect(
            $this->getJson('/api/v1/legacy-orders?' . http_build_query($query))
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($sale->id, $ids(['min_order_total' => 10000]));
        $this->assertNotContains($other->id, $ids(['min_order_total' => 20000]));
        $this->assertContains($sale->id, $ids(['max_order_total' => 20000]));
        $this->assertNotContains($other->id, $ids(['max_order_total' => 20000]));
        $this->assertContains($sale->id, $ids(['min_order_total' => 10000, 'max_order_total' => 20000]));
        $this->assertSame([$sale->id], $ids(['order_total' => 12500]));
        $this->assertContains($sale->id, $ids(['q' => '12500']));
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
                'legacy_order_total' => 200,
                'legacy_preserve_amounts' => true,
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
