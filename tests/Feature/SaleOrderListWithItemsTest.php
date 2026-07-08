<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SaleOrderListWithItemsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_sales_list_with_items_includes_line_items_in_response(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = Product::query()->firstOrFail();
        $sale = Sale::query()->create([
            'order_num' => 994001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'paid',
            'payment_status' => 'paid',
            'order_total' => 500,
            'amount_paid' => 500,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => 2,
            'uom' => $product->uom,
            'selling_price' => 250,
            'discount_given' => 0,
            'product_vat' => 0,
            'amount' => 500,
            'on_wholesale_retail' => 0,
        ]);

        $response = $this->getJson('/api/v1/sales?with_items=1&per_page=200');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $sale->id);
        $this->assertNotNull($row, 'Sale should appear in list response.');
        $this->assertIsArray($row['items'] ?? null);
        $this->assertCount(1, $row['items']);
        $this->assertSame($product->product_code, $row['items'][0]['product_code']);
    }
}
