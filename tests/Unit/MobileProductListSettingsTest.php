<?php

namespace Tests\Unit;

use App\Services\Sales\MobileProductListSettings;
use PHPUnit\Framework\TestCase;

class MobileProductListSettingsTest extends TestCase
{
    public function test_defaults_to_in_stock_only(): void
    {
        $service = new MobileProductListSettings;

        $this->assertSame(
            MobileProductListSettings::MODE_IN_STOCK_ONLY,
            $service->mode([]),
        );
        $this->assertTrue($service->filtersToInStockOnly([]));
    }

    public function test_all_products_mode_disables_in_stock_filter(): void
    {
        $service = new MobileProductListSettings;

        $this->assertSame(
            MobileProductListSettings::MODE_ALL_PRODUCTS,
            $service->mode(['mobile_product_list_mode' => 'all_products']),
        );
        $this->assertFalse($service->filtersToInStockOnly([
            'mobile_product_list_mode' => 'all_products',
        ]));
    }

    public function test_unknown_mode_falls_back_to_in_stock_only(): void
    {
        $service = new MobileProductListSettings;

        $this->assertSame(
            MobileProductListSettings::MODE_IN_STOCK_ONLY,
            $service->mode(['mobile_product_list_mode' => 'invalid']),
        );
    }
}
