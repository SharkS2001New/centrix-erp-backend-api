<?php

namespace Tests\Unit;

use App\Services\Mpesa\MpesaPaymentReferenceParser;
use PHPUnit\Framework\TestCase;

class MpesaPaymentReferenceParserTest extends TestCase
{
    public function test_parses_order_reference_prefix(): void
    {
        $parser = new MpesaPaymentReferenceParser();

        $this->assertSame(['order_num' => 12], $parser->parse('S12'));
        $this->assertSame(['order_num' => 12], $parser->parse('s0012'));
        $this->assertSame(['order_num' => 42], $parser->parse('42'));
    }

    public function test_parses_customer_reference_prefix(): void
    {
        $parser = new MpesaPaymentReferenceParser();

        $this->assertSame(['customer_num' => 5002], $parser->parse('C5002'));
    }

    public function test_extracts_order_number_from_noisy_reference(): void
    {
        $parser = new MpesaPaymentReferenceParser();

        $this->assertSame(['order_num' => 88], $parser->parse('ORDER-S88'));
    }
}
