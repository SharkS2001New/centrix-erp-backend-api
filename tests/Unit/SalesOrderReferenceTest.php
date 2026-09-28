<?php

namespace Tests\Unit;

use App\Support\SalesOrderReference;
use Tests\TestCase;

class SalesOrderReferenceTest extends TestCase
{
    public function test_parses_s_prefix_display_numbers(): void
    {
        $this->assertSame(1537, SalesOrderReference::parseOrderNum('S1537'));
        $this->assertSame(1537, SalesOrderReference::parseOrderNum('s1537'));
        $this->assertSame(34, SalesOrderReference::parseOrderNum('S0034'));
        $this->assertSame(1537, SalesOrderReference::parseOrderNum('#S1537'));
    }

    public function test_parses_plain_digits_and_phrases(): void
    {
        $this->assertSame(1537, SalesOrderReference::parseOrderNum('1537'));
        $this->assertSame(1537, SalesOrderReference::parseOrderNum('invoice 1537'));
        $this->assertSame(1537, SalesOrderReference::parseOrderNum('please check invoice 1537..i have wrongly paid it'));
        $this->assertSame(1537, SalesOrderReference::parseOrderNum('order S1537'));
    }

    public function test_parses_ar_invoice_numbers(): void
    {
        $this->assertSame(1537, SalesOrderReference::parseOrderNum('AR-1537'));
        $this->assertSame(1537, SalesOrderReference::parseOrderNum('AR1537'));
        $this->assertSame(1537, SalesOrderReference::parseOrderNum('AR-1537-S99'));
    }

    public function test_display_matches_frontend_padding(): void
    {
        $this->assertSame('S1537', SalesOrderReference::display(1537));
        $this->assertSame('S0034', SalesOrderReference::display(34));
        $this->assertNull(SalesOrderReference::display(0));
        $this->assertNull(SalesOrderReference::display(null));
    }

    public function test_rejects_empty_or_unrelated(): void
    {
        $this->assertNull(SalesOrderReference::parseOrderNum(''));
        $this->assertNull(SalesOrderReference::parseOrderNum('   '));
        $this->assertNull(SalesOrderReference::parseOrderNum('hello'));
    }
}
