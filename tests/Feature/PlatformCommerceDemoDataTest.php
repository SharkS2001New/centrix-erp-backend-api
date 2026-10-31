<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CurrentStock;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Platform\PlatformDemoDataService;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformCommerceDemoDataTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_all_commerce_profiles_generate_reusable_sample_products_with_images_and_stock(): void
    {
        Storage::fake('public');

        $org = Organization::query()->where('company_code', 'DEMO')->firstOrFail();
        $branch = Branch::query()->where('organization_id', $org->id)->firstOrFail();
        User::query()->where('organization_id', $org->id)->where('is_admin', true)->firstOrFail();
        $service = app(PlatformDemoDataService::class);

        foreach (['small_shop', 'wholesale_retail', 'supermarket', 'distribution', 'custom'] as $profile) {
            $org->deployment_profile = $profile;
            $org->save();

            $result = $service->seedOrganization($org->fresh());

            $this->assertSame($profile, $result['profile']);
            $this->assertSame(8, $result['products']);
            $this->assertSame(8, $result['images']);
            $this->assertSame(8, Product::query()
                ->where('organization_id', $org->id)
                ->where('product_code', 'like', 'DEMO-COM-'.$org->id.'-%')
                ->count());

            $products = Product::query()
                ->where('organization_id', $org->id)
                ->where('product_code', 'like', 'DEMO-COM-'.$org->id.'-%')
                ->get();
            foreach ($products as $product) {
                $this->assertNotEmpty($product->image_path);
                $this->assertTrue(Storage::disk('public')->exists($product->image_path));
            }

            $this->assertSame(8, CurrentStock::query()
                ->whereIn('product_code', $result['product_codes'])
                ->where('branch_id', $branch->id)
                ->count());
        }

        $service->clearOrganization($org->fresh());

        $this->assertSame(0, Product::query()
            ->where('organization_id', $org->id)
            ->where('product_code', 'like', 'DEMO-COM-'.$org->id.'-%')
            ->count());
        $this->assertSame(0, CurrentStock::query()
            ->where('branch_id', $branch->id)
            ->where('product_code', 'like', 'DEMO-COM-'.$org->id.'-%')
            ->count());
    }
}
