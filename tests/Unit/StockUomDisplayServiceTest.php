<?php

namespace Tests\Unit;

use App\Models\Uom;
use App\Services\Inventory\StockUomDisplayService;
use Tests\TestCase;

class StockUomDisplayServiceTest extends TestCase
{
    public function test_format_mixed_stock_splits_full_pack_and_remainder(): void
    {
        $uom = new Uom([
            'conversion_factor' => 50,
            'full_name' => 'Bag',
            'small_packaging_label' => 'kg',
            'uom_type' => 'weight',
            'uses_small_packaging' => true,
        ]);

        $display = app(StockUomDisplayService::class)->formatMixedStockDisplay(140, $uom);

        $this->assertSame('2 Bag, 40 kg', $display['text']);
    }

    public function test_format_mixed_stock_uses_full_package_only_uom(): void
    {
        $uom = new Uom([
            'conversion_factor' => 1,
            'full_name' => 'Jerican',
            'uses_small_packaging' => false,
        ]);

        $display = app(StockUomDisplayService::class)->formatMixedStockDisplay(25, $uom);

        $this->assertSame('25 Jerican', $display['text']);
    }

    public function test_fulfillment_quantity_labels_match_mixed_display(): void
    {
        $uom = new Uom([
            'conversion_factor' => 24,
            'full_name' => 'Bag of 24',
            'small_packaging_label' => 'units',
            'middle_packaging_label' => 'bag',
            'middle_factor' => 24,
            'uses_small_packaging' => true,
        ]);

        $labels = app(StockUomDisplayService::class)->fulfillmentQuantityLabels(48, $uom);

        $this->assertSame('48 units', $labels['quantity_label']);
        $this->assertSame('2 Bag of 24', $labels['pack_breakdown']);
    }
}
