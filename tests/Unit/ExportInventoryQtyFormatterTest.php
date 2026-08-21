<?php

namespace Tests\Unit;

use App\Services\Background\ExportInventoryQtyFormatter;
use Tests\TestCase;

class ExportInventoryQtyFormatterTest extends TestCase
{
    public function test_formats_shop_quantity_with_row_uom_metadata(): void
    {
        $formatter = app(ExportInventoryQtyFormatter::class);
        $text = $formatter->format(140, [
            'uom_name' => 'Bag',
            'conversion_factor' => 50,
            'small_packaging_label' => 'kg',
        ], 'shop_quantity');

        $this->assertSame('2 Bag, 40 kg', $text);
    }

    public function test_leaves_already_formatted_strings_alone(): void
    {
        $formatter = app(ExportInventoryQtyFormatter::class);
        $text = $formatter->format('2 Bag, 40 kg', [
            'conversion_factor' => 50,
            'uom_name' => 'Bag',
        ], 'shop_qty');

        $this->assertSame('2 Bag, 40 kg', $text);
    }

    public function test_ignores_non_inventory_keys(): void
    {
        $formatter = app(ExportInventoryQtyFormatter::class);
        $this->assertNull($formatter->format(120, ['conversion_factor' => 50], 'unit_price'));
        $this->assertNull($formatter->format(5, [], 'order_count'));
    }
}
