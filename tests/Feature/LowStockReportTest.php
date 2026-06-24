<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LowStockReportTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_low_stock_report_includes_out_of_stock_products(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = Product::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        DB::table('current_stock')->updateOrInsert(
            [
                'product_code' => $product->product_code,
                'branch_id' => $admin->branch_id,
            ],
            [
                'shop_quantity' => 0,
                'store_quantity' => 0,
            ],
        );

        $product->update([
            'low_stock_alert_enabled' => false,
            'reorder_point' => 0,
        ]);

        $response = $this->getJson('/api/v1/reports/low-stock?branch_id='.$admin->branch_id.'&per_page=200');
        $response->assertOk();

        $codes = collect($response->json('data'))->pluck('product_code')->all();
        $this->assertContains($product->product_code, $codes);
    }

    public function test_stock_on_hand_report_includes_products_without_current_stock_row(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = Product::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->firstOrFail();

        DB::table('current_stock')
            ->where('product_code', $product->product_code)
            ->where('branch_id', $admin->branch_id)
            ->delete();

        $response = $this->getJson('/api/v1/reports/stock-on-hand?branch_id='.$admin->branch_id.'&per_page=200');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('product_code', $product->product_code);
        $this->assertNotNull($row);
        $this->assertSame(0.0, (float) $row['total_base_units']);
    }

    public function test_price_list_report_returns_paginated_org_scoped_rows(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = Product::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $response = $this->getJson('/api/v1/reports/price-list?per_page=50');
        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'current_page',
            'last_page',
            'per_page',
            'total',
        ]);

        $codes = collect($response->json('data'))->pluck('product_code')->all();
        $this->assertContains($product->product_code, $codes);
    }
}
