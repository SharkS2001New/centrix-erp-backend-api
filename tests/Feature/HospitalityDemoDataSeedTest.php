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
}
