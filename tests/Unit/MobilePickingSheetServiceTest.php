<?php

namespace Tests\Unit;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Uom;
use App\Services\Fulfillment\LoadingListBuilder;
use App\Services\Fulfillment\MobileLoadingSheetService;
use App\Services\Fulfillment\MobilePickingSheetService;
use App\Services\Inventory\StockUomDisplayService;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MobilePickingSheetServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_retail_breakdown_lists_qty_amounts_without_customer_names(): void
    {
        $stockUom = Mockery::mock(StockUomDisplayService::class);
        $service = $this->makeService($stockUom);
        $uom = new Uom(['conversion_factor' => 50, 'full_name' => 'Bag', 'small_packaging_label' => 'kg']);

        $items = collect([
            $this->retailItem('Jane Wanjiku', 12),
            $this->retailItem('Peter Otieno', 10),
            $this->retailItem('Jane Wanjiku', 8), // same customer → merge to 20 kg
        ]);

        $breakdown = $this->invoke($service, 'buildRetailQtyBreakdown', [$items, $uom]);

        $this->assertSame('20 kg, 10 kg', $breakdown);
        $this->assertStringNotContainsString('Jane', $breakdown);
        $this->assertStringNotContainsString('×', $breakdown);
    }

    public function test_retail_qty_stays_in_kg_not_converted_to_bags(): void
    {
        $stockUom = Mockery::mock(StockUomDisplayService::class);
        $stockUom->shouldReceive('formatMixedStockDisplay')
            ->once()
            ->with(100.0, Mockery::type(Uom::class))
            ->andReturn(['text' => '2 Bag']);

        $service = $this->makeService($stockUom);
        $uom = new Uom(['conversion_factor' => 50, 'full_name' => 'Bag', 'small_packaging_label' => 'kg']);

        $this->assertSame('2 Bag', $this->invoke($service, 'formatWholesaleQtyLabel', [100.0, $uom]));
        $this->assertSame('150 kg', $this->invoke($service, 'formatRetailQtyLabel', [150.0, $uom]));
    }

    public function test_distinct_sold_prices_are_not_averaged(): void
    {
        $stockUom = Mockery::mock(StockUomDisplayService::class);
        $service = $this->makeService($stockUom);
        $uom = new Uom(['conversion_factor' => 50, 'full_name' => 'Bag', 'small_packaging_label' => 'kg']);

        $wholesale = collect([
            $this->wholesaleItem(100, 2250, 4500),
            $this->wholesaleItem(100, 2250, 4500),
        ]);
        $retail = collect([
            $this->retailItem('A', 20, 48, 960),
            $this->retailItem('B', 10, 52, 520),
        ]);

        $wPrices = $this->invoke($service, 'distinctSoldUnitPrices', [$wholesale, $uom, false]);
        $rPrices = $this->invoke($service, 'distinctSoldUnitPrices', [$retail, $uom, true]);

        $this->assertSame([2250.0], $wPrices);
        $this->assertSame([48.0, 52.0], $rPrices);
    }

    public function test_sold_price_reverses_from_amount_when_display_missing(): void
    {
        $stockUom = Mockery::mock(StockUomDisplayService::class);
        $service = $this->makeService($stockUom);
        $uom = new Uom(['conversion_factor' => 50, 'full_name' => 'Bag', 'small_packaging_label' => 'kg']);

        $item = new SaleItem([
            'quantity' => 25,
            'display_unit_price' => 0,
            'amount' => 3465,
            'discount_given' => 0,
            'on_wholesale_retail' => 1,
        ]);

        $price = $this->invoke($service, 'soldDisplayUnitPrice', [$item, $uom, true]);
        $this->assertSame(139.0, $price);
    }

    protected function makeService(StockUomDisplayService $stockUom): MobilePickingSheetService
    {
        return new MobilePickingSheetService(
            Mockery::mock(MobileLoadingSheetService::class),
            Mockery::mock(LoadingListBuilder::class),
            $stockUom,
        );
    }

    /** @param  list<mixed>  $args */
    protected function invoke(object $service, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($service, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($service, $args);
    }

    protected function retailItem(
        string $customerName,
        float $qty,
        float $displayUnitPrice = 48,
        float $amount = 0,
    ): SaleItem {
        $item = new SaleItem([
            'quantity' => $qty,
            'display_unit_price' => $displayUnitPrice,
            'amount' => $amount > 0 ? $amount : $qty * $displayUnitPrice,
            'discount_given' => 0,
            'on_wholesale_retail' => 1,
        ]);
        $sale = new Sale(['customer_name_override' => $customerName, 'customer_num' => null]);
        $sale->setRelation('customer', null);
        $item->setRelation('sale', $sale);

        return $item;
    }

    protected function wholesaleItem(float $baseQty, float $displayUnitPrice, float $amount): SaleItem
    {
        return new SaleItem([
            'quantity' => $baseQty,
            'display_unit_price' => $displayUnitPrice,
            'amount' => $amount,
            'discount_given' => 0,
            'on_wholesale_retail' => 0,
        ]);
    }
}
