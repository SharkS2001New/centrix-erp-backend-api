<?php

namespace Tests\Feature\Ai;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Tools\FindCatalogueExceptionsTool;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class FindCatalogueExceptionsToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_finds_products_with_cost_above_selling_price(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $code = 'AI-COST-HI-'.substr((string) microtime(true), -6);
        Product::query()->create([
            'product_code' => $code,
            'product_name' => 'Cost Above Selling Fixture',
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'unit_price' => 100,
            'last_cost_price' => 150,
            'stock_in_shop' => 5,
            'stock_in_store' => 0,
            'subcategory_id' => Product::query()->where('organization_id', $admin->organization_id)->value('subcategory_id'),
            'unit_id' => Product::query()->where('organization_id', $admin->organization_id)->value('unit_id'),
            'vat_id' => Product::query()->where('organization_id', $admin->organization_id)->value('vat_id'),
        ]);

        $result = app(FindCatalogueExceptionsTool::class)->execute($admin, [
            'check' => 'cost_above_selling',
            'limit' => 50,
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('cost_above_selling', $result['check']);
        $this->assertGreaterThanOrEqual(1, (int) ($result['totals']['cost_above_selling'] ?? 0));

        $match = collect($result['products'] ?? [])->firstWhere('product_code', $code);
        $this->assertNotNull($match, 'Expected fixture product in cost_above_selling results.');
        $this->assertEqualsWithDelta(100.0, (float) $match['unit_price'], 0.01);
        $this->assertEqualsWithDelta(150.0, (float) $match['last_cost_price'], 0.01);
        $this->assertLessThan(0, (float) $match['margin_pct']);
    }

    public function test_all_check_returns_totals_for_each_bucket(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $result = app(FindCatalogueExceptionsTool::class)->execute($admin, [
            'check' => 'all',
            'limit' => 5,
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('all', $result['check']);
        foreach ([
            'cost_above_selling',
            'zero_selling_price',
            'zero_cost_price',
            'thin_margin',
            'missing_supplier',
            'missing_reorder_point',
            'below_reorder',
            'missing_vat',
            'missing_uom',
        ] as $key) {
            $this->assertArrayHasKey($key, $result['totals'] ?? []);
            $this->assertArrayHasKey($key, $result['products'] ?? []);
        }
    }
}
