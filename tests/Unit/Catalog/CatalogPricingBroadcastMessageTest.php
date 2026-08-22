<?php

namespace Tests\Unit\Catalog;

use App\Services\Catalog\CatalogPricingBroadcastService;
use Tests\TestCase;

class CatalogPricingBroadcastMessageTest extends TestCase
{
    public function test_price_only_message(): void
    {
        $service = app(CatalogPricingBroadcastService::class);

        $message = $service->buildProductPricingMessage([
            'product_name' => 'Sugar 2kg',
            'price_from' => 100,
            'price_to' => 120,
        ]);

        $this->assertSame(
            'Price of Sugar 2kg has been updated from KES 100.00 to KES 120.00',
            $message,
        );
    }

    public function test_markup_only_message(): void
    {
        $service = app(CatalogPricingBroadcastService::class);

        $message = $service->buildProductPricingMessage([
            'product_name' => 'Sugar 2kg',
            'markup_from' => 5,
            'markup_to' => 15,
        ]);

        $this->assertSame(
            'Retail markup of KES 15.00 for Sugar 2kg',
            $message,
        );
    }

    public function test_combined_price_and_markup_message(): void
    {
        $service = app(CatalogPricingBroadcastService::class);

        $message = $service->buildProductPricingMessage([
            'product_name' => 'Sugar 2kg',
            'price_from' => 100,
            'price_to' => 120,
            'markup_from' => 5,
            'markup_to' => 15,
        ]);

        $this->assertSame(
            'Price of Sugar 2kg has been updated from KES 100.00 to KES 120.00, Retail markup of KES 15.00',
            $message,
        );
    }
}
