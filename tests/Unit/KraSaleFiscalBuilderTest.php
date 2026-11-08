<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceService;
use App\Services\Kra\KraSaleFiscalBuilder;
use ReflectionMethod;
use Tests\TestCase;

class KraSaleFiscalBuilderTest extends TestCase
{
    public function test_allocate_scales_lines_so_sum_equals_order_total_after_discount(): void
    {
        $items = KraSaleFiscalBuilder::allocateToTargetTotal([
            [
                'product_name' => 'A',
                'product_code' => 'A',
                'quantity' => 1,
                'amount' => 116.0,
                'product_vat' => 16.0,
                'vat_rate' => 16.0,
            ],
            [
                'product_name' => 'B',
                'product_code' => 'B',
                'quantity' => 1,
                'amount' => 232.0,
                'product_vat' => 32.0,
                'vat_rate' => 16.0,
            ],
        ], 313.20); // 348 − 34.80 order discount

        $sum = round(array_sum(array_column($items, 'amount')), 2);
        $this->assertSame(313.20, $sum);
        $this->assertEqualsWithDelta(43.20, round(array_sum(array_column($items, 'product_vat')), 2), 0.02);
    }

    public function test_workflow_payload_plu_total_matches_discounted_order_total(): void
    {
        $service = KraDeviceService::fromSettings([
            'kra_device_ip' => 'http://127.0.0.1:4000',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
        ]);
        $method = new ReflectionMethod(KraDeviceService::class, 'buildWorkflowPayload');
        $method->setAccessible(true);

        $orderItems = KraSaleFiscalBuilder::allocateToTargetTotal([
            [
                'product_name' => 'Widget',
                'product_code' => 'W1',
                'quantity' => 2,
                'amount' => 232.0,
                'product_vat' => 32.0,
                'vat_rate' => 16.0,
            ],
        ], 200.0);

        $payload = $method->invoke(
            $service,
            $orderItems,
            200.0,
            '1000000099',
            'original',
            '',
            '',
            null,
            ['cash' => 200.0, 'check' => 0.0, 'card' => 0.0],
        );

        $pluSum = round(array_sum(array_map(
            fn ($line) => (float) $line['SaleAmount'],
            $payload['plu_data'],
        )), 2);

        $this->assertSame(200.0, $pluSum);
        $this->assertSame('200.00', $payload['sign_structure']['CashAmt']);
        $totals = KraSaleFiscalBuilder::totalsFromRequestPayload($payload);
        $this->assertSame(200.0, $totals['gross']);
        $this->assertEqualsWithDelta(27.59, (float) $totals['vat'], 0.02);
    }

    public function test_workflow_trims_overpay_tenders_to_plu_total(): void
    {
        $service = KraDeviceService::fromSettings([
            'kra_device_ip' => 'http://127.0.0.1:4000',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
        ]);
        $method = new ReflectionMethod(KraDeviceService::class, 'buildWorkflowPayload');
        $method->setAccessible(true);

        $payload = $method->invoke(
            $service,
            [['product_name' => 'Orange', 'quantity' => 1, 'amount' => 100, 'vat_rate' => 16]],
            100.0,
            '1000000100',
            'original',
            '',
            '',
            null,
            ['cash' => 80.0, 'check' => 0.0, 'card' => 50.0],
        );

        $sign = $payload['sign_structure'];
        $this->assertSame('50.00', $sign['CashAmt']);
        $this->assertSame('0.00', $sign['CheckAmt']);
        $this->assertSame('50.00', $sign['CardAmt']);
    }
}
