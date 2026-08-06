<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceService;
use App\Services\Kra\SalesVatCalculator;
use Tests\TestCase;

class KraWorkflowPayloadTest extends TestCase
{
    public function test_workflow_plu_line_uses_empty_barcode_and_product_name(): void
    {
        $line = KraDeviceService::buildWorkflowPluLine([
            'product_name' => 'Orange Juice',
            'product_code' => 'PRD#0001',
            'quantity' => 2,
            'amount' => 200.0,
            'product_vat' => 27.59,
        ]);

        $this->assertSame('Orange Juice', $line['item_Name']);
        $this->assertSame('', $line['Barcode']);
        $this->assertSame('100.00', $line['SalePrice']);
        $this->assertSame('2', $line['SaleQty']);
        $this->assertSame('200.00', $line['SaleAmount']);
    }

    public function test_lightstores_vat_summary_matches_csharp_logic(): void
    {
        $summary = SalesVatCalculator::summarizeForLightStoresWorkflow([
            ['amount' => 116.0, 'product_vat' => 16.0],
            ['amount' => 50.0, 'product_vat' => 0.0],
        ]);

        $this->assertSame(100.0, $summary['vat16_net']);
        $this->assertSame(16.0, $summary['vat16_value']);
        $this->assertSame(50.0, $summary['exempt_net']);
    }

    public function test_workflow_plu_line_uses_four_decimal_price_for_fractional_qty(): void
    {
        $line = KraDeviceService::buildWorkflowPluLine([
            'product_name' => 'Sugar',
            'product_code' => 'SUGAR',
            'quantity' => 12.5,
            'amount' => 416.63,
            'product_vat' => 57.47,
        ]);

        $this->assertSame('12.5', $line['SaleQty']);
        $this->assertSame('416.63', $line['SaleAmount']);
        $this->assertSame('33.3304', $line['SalePrice']);
        $this->assertEqualsWithDelta(416.63, (float) $line['SalePrice'] * 12.5, 0.0001);
    }

    public function test_workflow_plu_line_keeps_two_decimal_price_when_it_reconciles(): void
    {
        $line = KraDeviceService::buildWorkflowPluLine([
            'product_name' => 'Sugar',
            'product_code' => 'SUGAR',
            'quantity' => 12.5,
            'amount' => 1592.5,
            'product_vat' => 219.66,
        ]);

        $this->assertSame('127.40', $line['SalePrice']);
        $this->assertEqualsWithDelta(1592.5, (float) $line['SalePrice'] * 12.5, 0.0001);
    }
}
