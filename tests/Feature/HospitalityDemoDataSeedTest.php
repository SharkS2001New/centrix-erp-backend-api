<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Hospitality\HospitalityDemoDataSeeder;
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

    public function test_seeder_creates_twenty_menu_products_and_tables_for_hotel_bar_org(): void
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

        $this->assertSame(20, $result['products']);
        $this->assertSame(8, $result['tables']);
        $this->assertCount(20, $result['product_codes']);

        $this->assertSame(
            20,
            Product::query()
                ->where('organization_id', $org->id)
                ->where('product_code', 'like', 'HTL-%')
                ->count(),
        );

        $again = app(HospitalityDemoDataSeeder::class)->seedForOrganization($org);
        $this->assertSame(20, $again['products']);
        $this->assertSame(
            20,
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
            ->assertJsonPath('products', 20)
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
