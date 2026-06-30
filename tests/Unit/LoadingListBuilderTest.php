<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Uom;
use App\Services\Fulfillment\LoadingListBuilder;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LoadingListBuilderTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_aggregate_lines_resolves_product_name_from_product_code(): void
    {
        $template = Sale::query()->firstOrFail();
        $product = Product::query()->where('product_code', '6161100100015')->firstOrFail();

        $sale = Sale::create([
            'order_num' => 96001,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $template->cashier_id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 3750,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'quantity' => 30,
            'selling_price' => 125,
            'amount' => 3750,
            'on_wholesale_retail' => 0,
        ]);

        $lines = app(LoadingListBuilder::class)->aggregateLinesFromSaleIds([$sale->id]);

        $this->assertCount(1, $lines);
        $line = $lines[0];
        $this->assertSame('Mumias White Sugar 50kg', $line['product_name']);
        $this->assertSame('30', $line['quantity_label']);
        $this->assertSame('30 units', $line['pack_breakdown']);
    }

    public function test_aggregate_lines_uses_pack_label_when_uom_has_conversion(): void
    {
        $template = Sale::query()->firstOrFail();
        $product = Product::query()->with('unit')->firstOrFail();

        $uom = Uom::query()->create([
            'conversion_factor' => 24,
            'full_name' => 'Bag of 24',
            'measure_name' => 'unit',
            'small_packaging_label' => 'units',
            'middle_packaging_label' => 'bag',
            'middle_factor' => 24,
            'uom_type' => 'pack',
            'is_base_unit' => false,
            'is_active' => true,
            'organization_id' => $template->organization_id,
        ]);
        $product->update(['unit_id' => $uom->id]);

        $sale = Sale::create([
            'order_num' => 96002,
            'branch_id' => $template->branch_id,
            'organization_id' => $template->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $template->cashier_id,
            'customer_num' => $template->customer_num,
            'route_id' => $template->route_id,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 3000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'quantity' => 48,
            'selling_price' => 125,
            'amount' => 6000,
            'on_wholesale_retail' => 0,
        ]);

        $lines = app(LoadingListBuilder::class)->aggregateLinesFromSaleIds([$sale->id]);

        $this->assertSame('2 bag', $lines[0]['quantity_label']);
        $this->assertSame('2 bag', $lines[0]['pack_breakdown']);
    }
}
