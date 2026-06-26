<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceService;
use ReflectionMethod;
use Tests\TestCase;

class KraCreditNotePayloadTest extends TestCase
{
    /** @return array<string, mixed> */
    protected function buildWorkflowPayload(
        array $orderItems,
        float $totalAmount,
        string $invoiceNumber,
        string $invoiceType,
        string $relevantInvoiceNumber,
        string $refundReasonCode,
    ): array {
        $service = KraDeviceService::fromSettings([
            'kra_device_ip' => 'http://127.0.0.1:4000',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'kra_device_test_mode' => false,
        ]);

        $method = new ReflectionMethod(KraDeviceService::class, 'buildWorkflowPayload');
        $method->setAccessible(true);

        return $method->invoke(
            $service,
            $orderItems,
            $totalAmount,
            $invoiceNumber,
            $invoiceType,
            $relevantInvoiceNumber,
            $refundReasonCode,
            null,
        );
    }

    public function test_sale_workflow_uses_original_invoice_type_and_cash_total(): void
    {
        $payload = $this->buildWorkflowPayload(
            [
                [
                    'product_name' => 'Orange',
                    'quantity' => 1,
                    'amount' => 116.0,
                    'product_vat' => 16.0,
                ],
            ],
            116.0,
            '1000000001',
            'original',
            '',
            '',
        );

        $sign = $payload['sign_structure'];
        $plu = $payload['plu_data'][0];

        $this->assertSame('original', $sign['InvoiceType']);
        $this->assertSame('', $sign['relevantInvoiceNumber']);
        $this->assertSame('', $sign['rfdRsnCd']);
        $this->assertSame('116.00', $sign['CashAmt']);
        $this->assertSame('0', $sign['CardAmt']);
        $this->assertSame('', $plu['Barcode']);
    }

    public function test_credit_note_matches_sale_payload_except_credit_fields(): void
    {
        $items = [
            [
                'product_name' => 'Orange',
                'quantity' => 1,
                'amount' => 116.0,
                'product_vat' => 16.0,
            ],
        ];

        $sale = $this->buildWorkflowPayload($items, 116.0, '1000000001', 'original', '', '');
        $credit = $this->buildWorkflowPayload($items, 116.0, '1000000002', 'credit', '82729', '03');

        $this->assertSame($sale['plu_data'], $credit['plu_data']);
        $this->assertSame($sale['sn'], $credit['sn']);
        $this->assertSame($sale['is_test'], $credit['is_test']);

        $saleSign = $sale['sign_structure'];
        $creditSign = $credit['sign_structure'];

        $this->assertSame('credit', $creditSign['InvoiceType']);
        $this->assertSame('82729', $creditSign['relevantInvoiceNumber']);
        $this->assertSame('03', $creditSign['rfdRsnCd']);

        foreach (['CashAmt', 'CardAmt', 'CheckAmt', 'SignType', 'Vat B(16.00%) net', 'Vat B(16.00%) value'] as $field) {
            $this->assertSame($saleSign[$field], $creditSign[$field], "Field {$field} should match sale");
        }
    }
}
