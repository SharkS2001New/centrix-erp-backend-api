<?php

namespace Tests\Unit\Ai;

use App\Models\Uom;
use App\Services\Ai\AiQtyLabelEnricher;
use App\Services\Inventory\StockUomDisplayService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiQtyLabelEnricherTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function enrich_product_rows_attaches_qty_label_from_stock_uom_display(): void
    {
        $uom = new Uom([
            'full_name' => 'Bag',
            'conversion_factor' => 50,
            'small_packaging_label' => 'kg',
            'uses_small_packaging' => true,
        ]);

        $display = Mockery::mock(StockUomDisplayService::class);
        $display->shouldReceive('formatMixedStockDisplay')
            ->once()
            ->with(90.0, Mockery::type(Uom::class))
            ->andReturn(['text' => '1 Bag, 40 kg', 'parts' => []]);

        $enricher = Mockery::mock(AiQtyLabelEnricher::class, [$display])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $enricher->shouldReceive('uomsByProductCode')
            ->once()
            ->with(7, ['MF001'])
            ->andReturn(['MF001' => $uom]);

        $rows = $enricher->enrichProductRows(7, [
            [
                'product_code' => 'MF001',
                'product_name' => 'Maize flour',
                'qty' => 90,
                'amount' => 4800,
            ],
        ]);

        $this->assertSame('1 Bag, 40 kg', $rows[0]['qty_label']);
        $this->assertSame(90.0, $rows[0]['qty_base']);
        $this->assertSame(90, $rows[0]['qty']);
    }

    #[Test]
    public function enrich_supports_custom_qty_and_label_keys(): void
    {
        $display = Mockery::mock(StockUomDisplayService::class);
        $display->shouldReceive('formatMixedStockDisplay')
            ->once()
            ->andReturn(['text' => '2 Bag', 'parts' => []]);

        $enricher = Mockery::mock(AiQtyLabelEnricher::class, [$display])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $enricher->shouldReceive('uomsByProductCode')
            ->once()
            ->andReturn(['X' => new Uom(['conversion_factor' => 50, 'full_name' => 'Bag'])]);

        $rows = $enricher->enrichProductRows(1, [
            ['product_code' => 'X', 'suggested_qty' => 100],
        ], 'suggested_qty', 'suggested_qty_label');

        $this->assertSame('2 Bag', $rows[0]['suggested_qty_label']);
        $this->assertArrayNotHasKey('qty_base', $rows[0]);
    }
}
