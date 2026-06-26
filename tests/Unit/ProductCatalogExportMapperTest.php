<?php

namespace Tests\Unit;

use App\Services\Background\ProductCatalogExportMapper;
use Tests\TestCase;

class ProductCatalogExportMapperTest extends TestCase
{
    public function test_maps_stock_and_pricing_fields_from_raw_api_shape(): void
    {
        $mapper = new ProductCatalogExportMapper();
        $mapped = $mapper->mapBatch([
            [
                'product_code' => 'PRD#0001',
                'product_name' => 'Cola',
                'unit_price' => 120,
                'last_cost_price' => 80,
                'discount_type' => 'percentage',
                'discount_percentage' => 5,
                'stock_in_shop' => 12,
                'stock_in_store' => 4,
                'sell_on_retail' => true,
                'deleted_at' => null,
            ],
        ]);

        $this->assertCount(1, $mapped);
        $row = $mapped[0];
        $this->assertSame('Uncategorised', $row['category_name']);
        $this->assertSame('General', $row['subcategory_name']);
        $this->assertSame(12, $row['shop_qty']);
        $this->assertSame(4, $row['store_qty']);
        $this->assertSame('—', $row['uom_label']);
        $this->assertSame('—', $row['supplier_name']);
        $this->assertSame('—', $row['vat_treatment']);
        $this->assertSame('Sells W/R', $row['pricing']);
        $this->assertSame('Yes', $row['is_active']);
        $this->assertSame(5, $row['discount']);
    }

    public function test_preserves_pre_enriched_rows_when_present(): void
    {
        $mapper = new ProductCatalogExportMapper();
        $mapped = $mapper->mapBatch([
            [
                'product_code' => 'PRD#0002',
                'product_name' => 'Water',
                'category_name' => 'Custom category',
                'subcategory_name' => 'Custom sub',
                'shop_qty' => 9,
                'store_qty' => 1,
                'uom_label' => 'Bottle',
                'supplier_name' => 'Custom supplier',
                'vat_treatment' => 'Invatable',
                'pricing' => 'Wholesale',
                'is_active' => true,
            ],
        ]);

        $row = $mapped[0];
        $this->assertSame('Custom category', $row['category_name']);
        $this->assertSame('Custom sub', $row['subcategory_name']);
        $this->assertSame(9, $row['shop_qty']);
        $this->assertSame('Bottle', $row['uom_label']);
        $this->assertSame('Invatable', $row['vat_treatment']);
        $this->assertSame('Yes', $row['is_active']);
    }

    public function test_marks_inactive_products_from_deleted_at(): void
    {
        $mapper = new ProductCatalogExportMapper();
        $mapped = $mapper->mapBatch([
            [
                'product_code' => 'PRD#0003',
                'product_name' => 'Retired SKU',
                'deleted_at' => '2026-01-01 00:00:00',
            ],
        ]);

        $this->assertSame('No', $mapped[0]['is_active']);
    }
}
