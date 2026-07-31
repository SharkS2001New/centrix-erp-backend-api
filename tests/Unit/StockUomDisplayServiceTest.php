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

        $this->assertSame('2 Bag of 24', $labels['quantity_label']);
        $this->assertSame('48 units', $labels['pack_breakdown']);
    }

    public function test_fulfillment_sort_quantity_uses_displayed_package_count(): void
    {
        $service = app(StockUomDisplayService::class);

        $bag = new Uom([
            'conversion_factor' => 50,
            'full_name' => 'Bag',
            'small_packaging_label' => 'kg',
            'uses_small_packaging' => true,
        ]);
        $jer = new Uom([
            'conversion_factor' => 1,
            'full_name' => 'Jer',
            'uses_small_packaging' => false,
        ]);
        $bale = new Uom([
            'conversion_factor' => 1,
            'full_name' => 'Bale',
            'uses_small_packaging' => false,
        ]);

        // Base qty would wrongly rank 4 bags (200) above 26 jer (26).
        $sortBag4 = $service->fulfillmentSortQuantity(200, $bag);
        $sortJer26 = $service->fulfillmentSortQuantity(26, $jer);
        $sortBale19 = $service->fulfillmentSortQuantity(19, $bale);
        $sortBag3 = $service->fulfillmentSortQuantity(150, $bag);

        $this->assertSame(4.0, $sortBag4);
        $this->assertSame(26.0, $sortJer26);
        $this->assertSame(19.0, $sortBale19);
        $this->assertSame(3.0, $sortBag3);

        $ordered = [$sortJer26, $sortBale19, $sortBag4, $sortBag3];
        $sorted = $ordered;
        rsort($sorted, SORT_NUMERIC);
        $this->assertSame([26.0, 19.0, 4.0, 3.0], $sorted);
    }
}
