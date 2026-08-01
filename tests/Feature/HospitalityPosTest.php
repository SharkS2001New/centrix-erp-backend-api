<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class HospitalityPosTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->org = Organization::query()->findOrFail($this->user->organization_id);
        $modules = $this->org->enabled_modules ?? [];
        $modules['hospitality'] = true;
        $modules['hospitality.bar_pos'] = true;
        $this->org->forceFill(['enabled_modules' => $modules])->save();
        Sanctum::actingAs($this->user);
    }

    public function test_catalog_ranks_most_sold_products_first(): void
    {
        $low = Product::query()
            ->where('organization_id', $this->org->id)
            ->orderBy('product_code')
            ->firstOrFail();
        $high = Product::query()
            ->where('organization_id', $this->org->id)
            ->where('product_code', '!=', $low->product_code)
            ->orderBy('product_code')
            ->firstOrFail();

        $template = Sale::query()->where('organization_id', $this->org->id)->first();
        if (! $template) {
            $this->markTestSkipped('Demo data has no sales for popularity ranking.');
        }

        $sale = $template->replicate([
            'order_num',
            'mpesa_transaction_code',
            'payment_reference',
        ]);
        $sale->order_num = 991001 + random_int(1, 9000);
        $sale->status = 'completed';
        $sale->payment_status = 'paid';
        $sale->amount_paid = 100;
        $sale->order_total = 100;
        $sale->archived = 0;
        $sale->completed_at = now();
        $sale->channel = 'pos';
        $sale->save();
        $saleId = (int) $sale->id;

        SaleItem::query()->create([
            'sale_id' => $saleId,
            'product_code' => $high->product_code,
            'line_no' => 1,
            'quantity' => 50,
            'selling_price' => $high->unit_price,
            'discount_given' => 0,
            'product_vat' => 0,
            'amount' => 50 * (float) $high->unit_price,
        ]);
        SaleItem::query()->create([
            'sale_id' => $saleId,
            'product_code' => $low->product_code,
            'line_no' => 2,
            'quantity' => 1,
            'selling_price' => $low->unit_price,
            'discount_given' => 0,
            'product_vat' => 0,
            'amount' => (float) $low->unit_price,
        ]);

        $items = $this->getJson('/api/v1/hospitality/pos/catalog?per_page=50')
            ->assertOk()
            ->json('items');

        $this->assertIsArray($items);
        $this->assertNotEmpty($items);
        $codes = array_column($items, 'product_code');
        $highPos = array_search($high->product_code, $codes, true);
        $lowPos = array_search($low->product_code, $codes, true);
        $this->assertNotFalse($highPos);
        $this->assertNotFalse($lowPos);
        $this->assertLessThan($lowPos, $highPos);
        $this->assertSame(4, $this->getJson('/api/v1/hospitality/pos/settings')->json('hotel_pos_grid_columns'));
    }

    public function test_open_check_tap_add_hold_and_settle_cash(): void
    {
        $product = Product::query()
            ->where('organization_id', $this->org->id)
            ->orderBy('product_code')
            ->firstOrFail();

        $opened = $this->postJson('/api/v1/hospitality/pos/checks', [])
            ->assertCreated()
            ->json('check');

        $this->assertSame('open', $opened['status']);
        $checkId = (int) $opened['id'];

        $withLine = $this->postJson("/api/v1/hospitality/pos/checks/{$checkId}/lines", [
            'product_code' => $product->product_code,
            'qty' => 2,
        ])
            ->assertOk()
            ->json('check');

        $this->assertCount(1, $withLine['lines']);
        $this->assertSame(2.0, (float) $withLine['lines'][0]['qty']);
        $this->assertGreaterThan(0, (float) $withLine['total']);

        $held = $this->postJson("/api/v1/hospitality/pos/checks/{$checkId}/hold")
            ->assertOk()
            ->json('check');
        $this->assertSame('held', $held['status']);

        $resumed = $this->postJson("/api/v1/hospitality/pos/checks/{$checkId}/resume")
            ->assertOk()
            ->json('check');
        $this->assertSame('open', $resumed['status']);

        $settled = $this->postJson("/api/v1/hospitality/pos/checks/{$checkId}/settle", [
            'method' => 'CASH',
        ])
            ->assertOk()
            ->json('check');

        $this->assertSame('settled', $settled['status']);
        $this->assertSame((float) $withLine['total'], (float) $settled['amount_paid']);
    }
}
