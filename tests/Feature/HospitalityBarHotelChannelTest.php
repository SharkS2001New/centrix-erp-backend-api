<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\HospitalityOutlet;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Hospitality\HospitalityCheckService;
use App\Services\Hospitality\HospitalityReportService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class HospitalityBarHotelChannelTest extends TestCase
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
        $modules['hospitality.reports'] = true;
        $this->org->forceFill(['enabled_modules' => $modules])->save();
        Sanctum::actingAs($this->user);
    }

    public function test_catalog_filters_by_outlet_channel(): void
    {
        $checks = app(HospitalityCheckService::class);
        $bar = $checks->ensureDefaultOutlet($this->org, null);
        $hotel = HospitalityOutlet::query()
            ->where('organization_id', $this->org->id)
            ->where('code', 'HOTEL')
            ->firstOrFail();

        $this->assertSame('bar', $bar->outlet_type);
        $this->assertSame('restaurant', $hotel->outlet_type);

        $template = Product::query()
            ->where('organization_id', $this->org->id)
            ->whereNotNull('subcategory_id')
            ->orderBy('product_code')
            ->firstOrFail();

        $barOnly = $template->replicate(['id', 'product_code', 'image_path', 'deleted_at', 'deleted_by']);
        $barOnly->exists = false;
        $barOnly->product_code = 'BARONLY1';
        $barOnly->product_name = 'Bar Only Drink';
        $barOnly->unit_price = 100;
        $barOnly->sell_on_bar = true;
        $barOnly->sell_on_hotel = false;
        $barOnly->sell_on_retail = false;
        $barOnly->save();

        $hotelOnly = $template->replicate(['id', 'product_code', 'image_path', 'deleted_at', 'deleted_by']);
        $hotelOnly->exists = false;
        $hotelOnly->product_code = 'HOTELONLY1';
        $hotelOnly->product_name = 'Hotel Only Dish';
        $hotelOnly->unit_price = 200;
        $hotelOnly->sell_on_bar = false;
        $hotelOnly->sell_on_hotel = true;
        $hotelOnly->sell_on_retail = false;
        $hotelOnly->save();

        $this->user->forceFill(['hospitality_outlet_id' => $bar->id])->save();
        $barCatalog = $this->getJson('/api/v1/hospitality/pos/catalog')->assertOk()->json();
        $barCodes = collect($barCatalog['items'])->pluck('product_code')->all();
        $this->assertContains('BARONLY1', $barCodes);
        $this->assertNotContains('HOTELONLY1', $barCodes);
        $this->assertSame('bar', $barCatalog['outlet']['menu_channel']);
        $this->assertSame('Bar', $barCatalog['outlet']['menu_channel_label']);

        $this->user->forceFill(['hospitality_outlet_id' => $hotel->id])->save();
        $hotelCatalog = $this->getJson('/api/v1/hospitality/pos/catalog')->assertOk()->json();
        $hotelCodes = collect($hotelCatalog['items'])->pluck('product_code')->all();
        $this->assertContains('HOTELONLY1', $hotelCodes);
        $this->assertNotContains('BARONLY1', $hotelCodes);
        $this->assertSame('hotel', $hotelCatalog['outlet']['menu_channel']);
        $this->assertSame('Restaurant', $hotelCatalog['outlet']['menu_channel_label']);
    }

    public function test_profit_loss_and_eod_reports_run(): void
    {
        $service = app(HospitalityReportService::class);
        $from = now()->toDateString();
        $to = now()->toDateString();

        $pl = $service->run($this->org, 'hospitality-profit-loss', $from, $to);
        $this->assertNotEmpty($pl['columns']);
        $this->assertCount(1, $pl['rows']);
        $this->assertArrayHasKey('gross_profit', $pl['rows'][0]);
        $this->assertArrayHasKey('cogs', $pl['rows'][0]);

        $eod = $service->run($this->org, 'hospitality-eod-cashier', $from, $to);
        $this->assertNotEmpty($eod['columns']);
        $this->assertIsArray($eod['rows']);

        $this->getJson('/api/v1/reports/hospitality-profit-loss?from='.$from.'&to='.$to)->assertOk();
        $this->getJson('/api/v1/reports/hospitality-eod-cashier?from='.$from.'&to='.$to)->assertOk();
    }
}
