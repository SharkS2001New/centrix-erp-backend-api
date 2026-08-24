<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\Uom;
use App\Models\User;
use App\Services\Ai\Tools\GetProductDetailsTool;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiProductDetailsToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_returns_uom_hierarchy_and_retail_packaging(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $template = Product::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($template);

        $uom = Uom::query()->create([
            'full_name' => 'Bag',
            'uom_type' => 'bag',
            'conversion_factor' => 50,
            'small_packaging_label' => 'kg',
            'uses_small_packaging' => true,
            'is_active' => true,
            'organization_id' => $admin->organization_id,
        ]);

        $product = Product::query()->create([
            'product_code' => 'AIUOM1',
            'product_name' => 'AI Test Maize Flour',
            'subcategory_id' => $template->subcategory_id,
            'vat_id' => $template->vat_id,
            'unit_id' => $uom->id,
            'unit_price' => 100,
            'last_cost_price' => 80,
            'stock_in_shop' => 90,
            'stock_in_store' => 0,
            'sell_on_retail' => true,
            'organization_id' => $admin->organization_id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        RetailPackageSetting::query()->where('product_code', $product->product_code)->delete();
        RetailPackageSetting::query()->create([
            'product_code' => $product->product_code,
            'max_qty_measure' => 50,
            'markup_price' => 5,
            'min_uom_measure' => 'kg',
            'max_uom_measure' => 'bag',
            'wholesale_markup_price' => 10,
        ]);

        /** @var GetProductDetailsTool $tool */
        $tool = app(GetProductDetailsTool::class);
        $result = $tool->execute($admin, ['product_code' => 'AIUOM1']);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('AIUOM1', $result['product']['product_code']);
        $this->assertTrue($result['measurements']['configured']);
        $this->assertSame(50.0, (float) $result['measurements']['conversion_factor']);
        $this->assertSame('kg', $result['measurements']['small_package_label']);
        $this->assertStringContainsString('Bag', (string) $result['stock']['shop_qty_label']);
        $this->assertTrue($result['retail_packaging']['configured']);
        $this->assertSame('kg', $result['retail_packaging']['min_uom_measure']);
        $this->assertTrue(
            collect($result['screens'])->pluck('path')->contains('/uoms'),
        );
        $this->assertTrue(
            collect($result['screens'])->pluck('path')->contains('/retail-package-settings'),
        );
    }

    public function test_finds_product_by_name_query(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $existing = Product::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($existing);

        /** @var GetProductDetailsTool $tool */
        $tool = app(GetProductDetailsTool::class);
        $result = $tool->execute($admin, [
            'query' => substr((string) $existing->product_name, 0, 8),
        ]);

        $this->assertFalse($result['error'] ?? false);
        $this->assertNotEmpty($result['product']['product_code'] ?? null);
        $this->assertArrayHasKey('measurements', $result);
    }
}
