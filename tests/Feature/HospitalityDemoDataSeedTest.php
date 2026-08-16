<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Hospitality\HospitalityDemoDataSeeder;
use App\Support\StoredPublicFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class HospitalityDemoDataSeedTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
    }

    public function test_seeder_creates_hotel_and_bar_menu_with_images(): void
    {
        $org = Organization::query()->create([
            'company_code' => 'HTLDEMO',
            'org_name' => 'Hotel Demo Org',
            'org_email' => 'hotel-demo@test.com',
            'primary_tel' => '0700111222',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => [
                'hospitality' => true,
                'hospitality.backend' => true,
                'hospitality.bar_pos' => true,
            ],
        ]);

        $result = app(HospitalityDemoDataSeeder::class)->seedForOrganization($org);

        $this->assertSame(38, $result['products']);
        $this->assertSame(8, $result['tables']);
        $this->assertCount(38, $result['product_codes']);

        $products = Product::query()
            ->where('organization_id', $org->id)
            ->where('product_code', 'like', 'HTL-%')
            ->get();
        $this->assertCount(38, $products);

        $byName = $products->keyBy(fn (Product $p) => (string) $p->product_name);
        foreach (['Chips', 'Soda', 'Beef stew', 'Milk and Tea', 'Black Coffee'] as $required) {
            $this->assertTrue($byName->has($required), "Missing hotel menu item: {$required}");
            $this->assertTrue((bool) $byName[$required]->sell_on_hotel, "{$required} should sell on hotel");
        }

        $this->assertSame(
            20,
            $products->filter(fn (Product $p) => str_starts_with((string) $p->product_code, 'HTL-A'))->count(),
        );
        $water = $byName->get('Water 1 litre');
        $this->assertNotNull($water);
        $this->assertTrue((bool) $water->sell_on_bar);
        $this->assertFalse((bool) $water->sell_on_hotel);

        $sodaSamples = $products->filter(fn (Product $p) => str_starts_with((string) $p->product_code, 'HTL-S'));
        $this->assertGreaterThanOrEqual(4, $sodaSamples->count());

        foreach ($products as $product) {
            $this->assertNotEmpty($product->image_path, "Missing image_path for {$product->product_code}");
            $this->assertTrue(
                StoredPublicFile::exists($product->image_path),
                "Missing seeded image file for {$product->product_code}",
            );
            $this->assertNotEmpty($product->image_url);
        }

        $again = app(HospitalityDemoDataSeeder::class)->seedForOrganization($org);
        $this->assertSame(38, $again['products']);
        $this->assertSame(
            38,
            Product::query()
                ->where('organization_id', $org->id)
                ->where('product_code', 'like', 'HTL-%')
                ->count(),
        );
    }

    public function test_platform_admin_can_seed_via_api(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $admin->forceFill(['is_super_admin' => true])->save();

        $org = Organization::query()->create([
            'company_code' => 'HTLAPI',
            'org_name' => 'Hotel API Seed Org',
            'org_email' => 'hotel-api@test.com',
            'primary_tel' => '0700333444',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'hotel_bar',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/organizations/{$org->id}/hospitality/seed-demo-data")
            ->assertOk()
            ->assertJsonPath('products', 38)
            ->assertJsonPath('tables', 8);
    }

    public function test_seed_rejects_non_hotel_bar_orgs(): void
    {
        $org = Organization::query()->create([
            'company_code' => 'RTLSEED',
            'org_name' => 'Retail Seed Org',
            'org_email' => 'retail-seed@test.com',
            'primary_tel' => '0700555666',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(HospitalityDemoDataSeeder::class)->seedForOrganization($org);
    }

    public function test_remove_clears_menu_orders_and_demo_tables(): void
    {
        $org = Organization::query()->create([
            'company_code' => 'HTLCLR',
            'org_name' => 'Hotel Clear Org',
            'org_email' => 'hotel-clear@test.com',
            'primary_tel' => '0700777888',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'hotel_bar',
        ]);

        $seeder = app(HospitalityDemoDataSeeder::class);
        $seeder->seedForOrganization($org);

        $keep = Product::query()
            ->where('organization_id', $org->id)
            ->where('product_code', 'like', 'HTL-%')
            ->orderBy('product_code')
            ->firstOrFail();
        $keepCode = 'KEEP-RTL-'.$org->id;
        $retail = $keep->replicate(['id', 'product_code', 'image_path', 'deleted_at', 'deleted_by']);
        $retail->product_code = $keepCode;
        $retail->product_name = 'Retail only keep';
        $retail->sell_on_hotel = false;
        $retail->sell_on_bar = false;
        $retail->sell_on_retail = true;
        $retail->image_path = null;
        $retail->save();

        $outlet = \App\Models\HospitalityOutlet::query()
            ->where('organization_id', $org->id)
            ->firstOrFail();
        $check = \App\Models\HospitalityCheck::query()->create([
            'organization_id' => $org->id,
            'outlet_id' => $outlet->id,
            'check_number' => 'HTL-TEST-1',
            'status' => 'open',
            'service_mode' => 'counter',
            'opened_at' => now(),
        ]);
        \App\Models\HospitalityCheckLine::query()->create([
            'organization_id' => $org->id,
            'check_id' => $check->id,
            'product_code' => 'HTL-F01',
            'description' => 'Ugali plate',
            'qty' => 1,
            'unit_price' => 250,
            'line_total' => 250,
        ]);

        $imagePaths = Product::query()
            ->where('organization_id', $org->id)
            ->where('product_code', 'like', 'HTL-%')
            ->pluck('image_path')
            ->filter()
            ->values();
        $this->assertNotEmpty($imagePaths);

        $result = $seeder->removeForOrganization($org);

        $this->assertSame(38, $result['products']);
        $this->assertSame(8, $result['tables']);
        $this->assertSame(1, $result['orders']);

        $this->assertSame(
            0,
            Product::withTrashed()
                ->where('organization_id', $org->id)
                ->where('product_code', 'like', 'HTL-%')
                ->count(),
        );
        $this->assertSame(
            0,
            \App\Models\HospitalityCheck::query()->where('organization_id', $org->id)->count(),
        );
        $this->assertSame(
            0,
            \App\Models\HospitalityFloorTable::query()
                ->where('organization_id', $org->id)
                ->whereIn('code', HospitalityDemoDataSeeder::DEMO_TABLE_CODES)
                ->count(),
        );
        $this->assertSame(
            2,
            \App\Models\HospitalityOutlet::query()->where('organization_id', $org->id)->count(),
        );
        $this->assertTrue(
            Product::query()
                ->where('organization_id', $org->id)
                ->where('product_code', $keepCode)
                ->exists(),
        );
        foreach ($imagePaths as $path) {
            $this->assertFalse(StoredPublicFile::exists($path), "Seeded image should be deleted: {$path}");
        }

        $again = $seeder->seedForOrganization($org);
        $this->assertSame(38, $again['products']);
        $this->assertSame(8, $again['tables']);
    }

    public function test_platform_admin_can_remove_via_api(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $admin->forceFill(['is_super_admin' => true])->save();

        $org = Organization::query()->create([
            'company_code' => 'HTLRMV',
            'org_name' => 'Hotel API Remove Org',
            'org_email' => 'hotel-rm@test.com',
            'primary_tel' => '0700999000',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'hotel_bar',
        ]);

        app(HospitalityDemoDataSeeder::class)->seedForOrganization($org);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/organizations/{$org->id}/hospitality/remove-demo-data")
            ->assertOk()
            ->assertJsonPath('products', 38)
            ->assertJsonPath('tables', 8)
            ->assertJsonPath('orders', 0);
    }

    public function test_remove_rejects_non_hotel_bar_orgs(): void
    {
        $org = Organization::query()->create([
            'company_code' => 'RTLRMV',
            'org_name' => 'Retail Remove Org',
            'org_email' => 'retail-rm@test.com',
            'primary_tel' => '0700111000',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(HospitalityDemoDataSeeder::class)->removeForOrganization($org);
    }
}
