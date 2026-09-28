<?php

namespace Tests\Unit;

use App\Models\Sale;
use App\Services\Erp\SalePaymentColumnMapper;
use PHPUnit\Framework\TestCase;

class SalePaymentColumnMapperTest extends TestCase
{
    public function test_increments_for_equity_and_cash(): void
    {
        $this->assertSame(['equity_amount' => 10000.0], SalePaymentColumnMapper::incrementsForMethod('EQUITY', 10000));
        $this->assertSame(['cash' => 500.0], SalePaymentColumnMapper::incrementsForMethod('CASH', 500));
        $this->assertSame([], SalePaymentColumnMapper::incrementsForMethod('EQUITY', 0));
    }

    public function test_replace_from_method_map_sets_absolute_columns_in_one_pass(): void
    {
        $sale = new class extends Sale
        {
            public array $lastUpdate = [];

            public function update(array $attributes = [], array $options = []): bool
            {
                foreach ($attributes as $key => $value) {
                    $this->setAttribute($key, $value);
                }
                $this->lastUpdate = $attributes;

                return true;
            }
        };

        $sale->cash = 99;
        $sale->mpesa_amount = 50;
        $sale->equity_amount = 10;
        $sale->kcb_amount = 5;

        SalePaymentColumnMapper::replaceFromMethodMap($sale, [
            'CASH' => 100,
            'MPESA' => 40,
            'EQUITY' => 25,
            'KCB' => 15,
            'COOP' => 200,
        ]);

        $this->assertSame(100.0, (float) $sale->cash);
        $this->assertSame(40.0, (float) $sale->mpesa_amount);
        $this->assertSame(25.0, (float) $sale->equity_amount);
        $this->assertSame(15.0, (float) $sale->kcb_amount);
        $this->assertSame([
            'cash' => 100.0,
            'mpesa_amount' => 40.0,
            'equity_amount' => 25.0,
            'kcb_amount' => 15.0,
        ], $sale->lastUpdate);
    }
}
