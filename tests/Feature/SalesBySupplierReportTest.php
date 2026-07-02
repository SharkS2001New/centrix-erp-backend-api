<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SalesBySupplierReportTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_sales_by_supplier_report_aggregates_completed_sales(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::where('product_code', '6161100100015')->firstOrFail();

        $sale = Sale::query()->create([
            'order_num' => 994001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 12000,
            'amount_paid' => 12000,
            'completed_at' => now(),
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'amount' => 12000,
            'product_vat' => 1600,
            'discount_given' => 0,
            'uom' => 'bag',
        ]);

        $today = now()->toDateString();

        $response = $this->getJson("/api/v1/reports/sales-by-supplier?from_date={$today}&to_date={$today}&date_column=sale_date&per_page=50")
            ->assertOk();

        $rows = collect($response->json('data'));
        $match = $rows->firstWhere('supplier_id', $supplier->id);

        $this->assertNotNull($match, 'Expected supplier row in sales-by-supplier report.');
        $this->assertSame('Mumias Sugar Co.', $match['supplier_name']);
        $this->assertSame(1, (int) $match['order_count']);
        $this->assertEqualsWithDelta(12000.0, (float) $match['total_revenue'], 0.01);
    }
}
