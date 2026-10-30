<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceService;
use App\Services\Kra\SalesVatCalculator;
use ReflectionMethod;
use Tests\TestCase;

class KraWorkflowPayloadTest extends TestCase
{
    /** @param array{cash: float, check: float, card: float} $tenders
     *  @return array<string, mixed>
     */
    protected function buildWorkflowPayloadWithTenders(array $tenders): array
    {
        $service = KraDeviceService::fromSettings([
            'kra_device_ip' => 'http://127.0.0.1:4000',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
        ]);
        $method = new ReflectionMethod(KraDeviceService::class, 'buildWorkflowPayload');
        $method->setAccessible(true);

        return $method->invoke(
            $service,
            [['product_name' => 'Orange', 'quantity' => 1, 'amount' => 100]],
            100.0,
            '1000000001',
            'original',
            '',
            '',
            null,
            $tenders,
        );
    }

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
        $this->assertSame('PRD#0001', $line['product_code']);
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

    public function test_workflow_plu_line_sanitizes_name_and_preserves_mobile_fractional_quantity(): void
    {
        $line = KraDeviceService::buildWorkflowPluLine([
            'product_name' => 'Maziwa 🥛 – 1L',
            'product_code' => 'MILK-1L',
            'quantity' => 0.333,
            'amount' => 33.30,
        ]);

        $this->assertSame('Maziwa - 1L', $line['item_Name']);
        $this->assertSame('0.333', $line['SaleQty']);
        $this->assertSame('100.00', $line['SalePrice']);
        $this->assertSame('33.30', $line['SaleAmount']);
        $this->assertEqualsWithDelta(
            (float) $line['SaleAmount'],
            (float) $line['SalePrice'] * (float) $line['SaleQty'],
            0.0001,
        );
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

    public function test_workflow_adds_tender_shortfall_to_cash_without_changing_other_tenders(): void
    {
        $sign = $this->buildWorkflowPayloadWithTenders([
            'cash' => 20.0,
            'check' => 10.0,
            'card' => 30.0,
        ])['sign_structure'];

        $this->assertSame('60.00', $sign['CashAmt']);
        $this->assertSame('10.00', $sign['CheckAmt']);
        $this->assertSame('30.00', $sign['CardAmt']);
    }

    public function test_workflow_unpaid_order_uses_plu_total_as_cash_to_reconcile(): void
    {
        $sign = $this->buildWorkflowPayloadWithTenders([
            'cash' => 0.0,
            'check' => 0.0,
            'card' => 0.0,
        ])['sign_structure'];

        $this->assertSame('100.00', $sign['CashAmt']);
        $this->assertSame('0.00', $sign['CheckAmt']);
        $this->assertSame('0.00', $sign['CardAmt']);
    }
}
