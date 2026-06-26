<?php

namespace Tests\Unit;

use App\Models\CreditNote;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Services\Kra\KraTraderInvoiceAllocator;
use ReflectionMethod;
use Tests\TestCase;

class KraTraderInvoiceAllocatorTest extends TestCase
{
    public function test_sale_generates_unique_numeric_trader_number(): void
    {
        $sale = Sale::make([
            'organization_id' => 42,
            'order_num' => 1205,
        ]);

        $allocator = $this->getMockBuilder(KraTraderInvoiceAllocator::class)
            ->onlyMethods(['traderNumberAlreadyUsed'])
            ->getMock();
        $allocator->method('traderNumberAlreadyUsed')->willReturn(false);

        $number = $allocator->forSale($sale);

        $this->assertTrue($allocator->isValidFormat($number));
        $this->assertNotSame('1205', $number);
    }

    public function test_sale_reuses_trader_number_from_prior_kra_attempt(): void
    {
        $sale = Sale::make([
            'id' => 99,
            'organization_id' => 1,
            'order_num' => 50,
        ]);
        $sale->exists = true;

        KraResponse::unguard();
        KraResponse::make([
            'id' => 1,
            'sale_id' => 99,
            'request_payload' => [
                'sign_structure' => ['TraderSystemInvoiceNumber' => '8765432101'],
            ],
        ]);
        KraResponse::reguard();

        $allocator = $this->getMockBuilder(KraTraderInvoiceAllocator::class)
            ->onlyMethods(['extractFromLatestSaleAttempt'])
            ->getMock();
        $allocator->method('extractFromLatestSaleAttempt')->willReturn('8765432101');

        $this->assertSame('8765432101', $allocator->forSale($sale));
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

    public function test_credit_note_reuses_existing_kra_request_payload_number(): void
    {
        $creditNote = CreditNote::make([
            'id' => 9,
            'organization_id' => 3,
            'kra_request_payload' => [
                'sign_structure' => ['TraderSystemInvoiceNumber' => '4455667788'],
            ],
        ]);

        $allocator = new KraTraderInvoiceAllocator;

        $this->assertSame('4455667788', $allocator->forCreditNote($creditNote));
    }

    public function test_credit_note_generates_unique_number_when_none_exists(): void
    {
        $creditNote = CreditNote::make([
            'id' => 9,
            'organization_id' => 3,
        ]);

        $allocator = $this->getMockBuilder(KraTraderInvoiceAllocator::class)
            ->onlyMethods(['traderNumberAlreadyUsed'])
            ->getMock();
        $allocator->method('traderNumberAlreadyUsed')->willReturn(false);

        $number = $allocator->forCreditNote($creditNote);

        $this->assertTrue($allocator->isValidFormat($number));
    }

    public function test_generate_candidate_uses_lightstores_timestamp_shape(): void
    {
        $this->travelTo('2026-06-27 01:39:11');

        $allocator = new KraTraderInvoiceAllocator;
        $method = new ReflectionMethod(KraTraderInvoiceAllocator::class, 'generateCandidate');
        $method->setAccessible(true);

        $number = $method->invoke($allocator);
        $expectedPrefix = substr((string) time(), 0, 7);

        $this->assertSame(10, strlen($number));
        $this->assertStringStartsWith($expectedPrefix, $number);
        $this->assertNotSame('6', $number);
    }
}
