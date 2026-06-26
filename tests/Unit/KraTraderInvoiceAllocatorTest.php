<?php

namespace Tests\Unit;

use App\Models\CreditNote;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Services\Kra\KraTraderInvoiceAllocator;
use Tests\TestCase;

class KraTraderInvoiceAllocatorTest extends TestCase
{
    public function test_sale_uses_order_num_when_above_prior_submissions(): void
    {
        $sale = Sale::make([
            'organization_id' => 42,
            'order_num' => 1205,
        ]);

        $allocator = $this->getMockBuilder(KraTraderInvoiceAllocator::class)
            ->onlyMethods(['maxUsedTraderInvoice'])
            ->getMock();
        $allocator->method('maxUsedTraderInvoice')->willReturn(900);

        $number = $allocator->forSale($sale, ['kra_trader_invoice_start' => 1000]);

        $this->assertSame('1205', $number);
    }

    public function test_sale_bumps_when_order_num_was_already_used_on_device(): void
    {
        $sale = Sale::make([
            'organization_id' => 1,
            'order_num' => 50,
        ]);

        KraResponse::unguard();
        KraResponse::make([
            'sale_id' => 1,
            'order_no' => 50,
            'request_payload' => [
                'sign_structure' => ['TraderSystemInvoiceNumber' => '50'],
            ],
        ]);
        KraResponse::reguard();

        $allocator = $this->getMockBuilder(KraTraderInvoiceAllocator::class)
            ->onlyMethods(['maxUsedTraderInvoice'])
            ->getMock();
        $allocator->method('maxUsedTraderInvoice')->willReturn(50);

        $this->assertSame('51', $allocator->forSale($sale));
    }

    public function test_respects_configured_start_offset_for_legacy_tim_sequence(): void
    {
        $sale = Sale::make([
            'organization_id' => 1,
            'order_num' => 12,
        ]);

        $allocator = $this->getMockBuilder(KraTraderInvoiceAllocator::class)
            ->onlyMethods(['maxUsedTraderInvoice'])
            ->getMock();
        $allocator->method('maxUsedTraderInvoice')->willReturn(0);

        $this->assertSame('50001', $allocator->forSale($sale, ['kra_trader_invoice_start' => 50001]));
    }

    public function test_extracts_trader_number_from_failed_kra_response_for_retry(): void
    {
        $row = KraResponse::make([
            'request_payload' => [
                'sign_structure' => ['TraderSystemInvoiceNumber' => '9876'],
            ],
        ]);

        $allocator = new KraTraderInvoiceAllocator;

        $this->assertSame('9876', $allocator->extractFromKraResponse($row));
    }

    public function test_credit_note_gets_next_available_trader_number(): void
    {
        $creditNote = CreditNote::make([
            'id' => 9,
            'organization_id' => 3,
        ]);

        $allocator = $this->getMockBuilder(KraTraderInvoiceAllocator::class)
            ->onlyMethods(['maxUsedTraderInvoice'])
            ->getMock();
        $allocator->method('maxUsedTraderInvoice')->willReturn(200);

        $this->assertSame('201', $allocator->forCreditNote($creditNote));
    }
}
