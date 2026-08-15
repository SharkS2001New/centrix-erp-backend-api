<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Fulfillment\LoadTonnagePresenter;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LoadTonnagePresenterTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_converts_kilograms_to_tonnes(): void
    {
        $presenter = app(LoadTonnagePresenter::class);

        $this->assertSame(8.0, $presenter->kgToTonnes(8000));
        $this->assertSame(0.01, $presenter->kgToTonnes(10));
    }

    public function test_line_tonnage_is_product_weight_times_base_quantity(): void
    {
        $product = Product::query()->firstOrFail();
        $product->update(['product_weight' => 2.5]);

        $presenter = app(LoadTonnagePresenter::class);
        $summary = $presenter->applyToLines([
            [
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'required_qty' => 4,
            ],
        ], $product->organization_id);

        $this->assertSame(10.0, $summary['total_weight_kg']);
        $this->assertSame(0.01, $summary['total_weight_tonnes']);
        $this->assertSame(0, $summary['missing_weight_count']);
        $this->assertSame(2.5, $summary['lines'][0]['product_weight']);
        $this->assertSame(10.0, $summary['lines'][0]['line_weight_kg']);
        $this->assertFalse($summary['lines'][0]['weight_missing']);
    }

    public function test_missing_product_weight_is_flagged_and_excluded_from_total(): void
    {
        $product = Product::query()->firstOrFail();
        $product->update(['product_weight' => 0]);

        $presenter = app(LoadTonnagePresenter::class);
        $summary = $presenter->applyToLines([
            [
                'product_code' => $product->product_code,
                'required_qty' => 4,
            ],
        ], $product->organization_id);

        $this->assertSame(0.0, $summary['total_weight_kg']);
        $this->assertSame(1, $summary['missing_weight_count']);
        $this->assertTrue($summary['lines'][0]['weight_missing']);
    }
}
