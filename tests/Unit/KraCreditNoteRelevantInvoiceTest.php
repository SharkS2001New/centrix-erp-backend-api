<?php

namespace Tests\Unit;

use App\Models\KraResponse;
use App\Services\Sales\CreditNoteService;
use Tests\TestCase;

class KraCreditNoteRelevantInvoiceTest extends TestCase
{
    public function test_credit_note_uses_the_original_sale_receipt_number_not_the_long_cu_number(): void
    {
        $kra = KraResponse::make([
            'invoice_number' => '1760123456',
            'status' => 'success',
            'request_payload' => [
                'sign_structure' => [
                    'InvoiceType' => 'original',
                    'TraderSystemInvoiceNumber' => '1760123456',
                ],
            ],
            'response_payload' => [
                'document_type' => 'sale',
                'cu_inv_no' => '019058765400000123',
                'invoice_number' => '1760123456',
            ],
        ]);

        $this->assertSame('1760123456', (new CreditNoteService())->relevantInvoiceFromKraResponse($kra));
    }

    public function test_a_short_cu_number_is_used_when_the_sale_has_no_trader_receipt(): void
    {
        $kra = KraResponse::make([
            'invoice_number' => 'CU-ORIG-1',
            'status' => 'success',
            'response_payload' => [
                'cu_inv_no' => '00005678',
            ],
        ]);

        $this->assertSame('5678', (new CreditNoteService())->relevantInvoiceFromKraResponse($kra));
    }

    public function test_a_long_cu_number_alone_is_not_sent_as_the_credit_note_reference(): void
    {
        $kra = KraResponse::make([
            'invoice_number' => 'POS-S12',
            'status' => 'success',
            'response_payload' => [
                'cu_inv_no' => '019058765400000123',
            ],
        ]);

        $this->assertSame('', (new CreditNoteService())->relevantInvoiceFromKraResponse($kra));
    }
}
