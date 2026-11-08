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
                $this->assertFalse(
                    (bool) filter_var($product->image_path, FILTER_VALIDATE_URL),
                    'Commerce demo photos should be stored on the public disk',
                );
                $this->assertTrue(Storage::disk('public')->exists($product->image_path));
                $this->assertStringContainsString('/image/file', (string) $product->image_url);
                $this->assertStringContainsString('v='.$product->image_version, (string) $product->image_url);
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

    public function test_platform_demo_generation_seeds_retail_wholesale_and_distribution_accounts(): void
    {
        Storage::fake('public');

        $service = app(PlatformDemoDataService::class);
        $result = $service->generate();

        $accounts = collect($result['organizations'])->keyBy('company_code');
        foreach (['RETAIL', 'WHOLESALE', 'DISTRIB'] as $code) {
            $this->assertTrue($accounts->get($code)['present']);
        }

        $this->assertSame(8, Product::query()
            ->where('organization_id', Organization::query()->where('company_code', 'RETAIL')->value('id'))
            ->count());
        $this->assertSame(8, Product::query()
            ->where('organization_id', Organization::query()->where('company_code', 'WHOLESALE')->value('id'))
            ->count());
        $this->assertSame(8, Product::query()
            ->where('organization_id', Organization::query()->where('company_code', 'DISTRIB')->value('id'))
            ->count());

        $retailNames = Product::query()
            ->where('organization_id', Organization::query()->where('company_code', 'RETAIL')->value('id'))
            ->pluck('product_name');
        $this->assertContains('Premium rice 2kg', $retailNames);
        $this->assertNotContains('Fresh bananas (bunch)', $retailNames);

        $wholesaleNames = Product::query()
            ->where('organization_id', Organization::query()->where('company_code', 'WHOLESALE')->value('id'))
            ->pluck('product_name');
        $this->assertContains('Premium rice 25kg sack', $wholesaleNames);

        $distributionNames = Product::query()
            ->where('organization_id', Organization::query()->where('company_code', 'DISTRIB')->value('id'))
            ->pluck('product_name');
        $this->assertContains('Rice 2kg retail pack (carton)', $distributionNames);

        $supermarketOrg = Organization::query()->where('company_code', 'RETAIL')->firstOrFail();
        $supermarketOrg->deployment_profile = 'supermarket';
        $supermarketOrg->save();
        $service->seedOrganization($supermarketOrg->fresh());
        $supermarketNames = Product::query()
            ->where('organization_id', $supermarketOrg->id)
            ->where('product_code', 'like', 'DEMO-COM-'.$supermarketOrg->id.'-%')
            ->pluck('product_name');
        $this->assertContains('Fresh bananas (bunch)', $supermarketNames);
        $this->assertContains('Laundry bar soap 1kg', $supermarketNames);
        $this->assertNotContains('Premium rice 25kg sack', $supermarketNames);

        $service->rollback();
        $this->assertFalse($service->status()['loaded']);
    }
}
