<?php

namespace Tests\Unit;

use App\Services\Platform\PlatformPaybillLicenceService;
use PHPUnit\Framework\TestCase;

class PlatformLicenceQuoteTest extends TestCase
{
    public function test_three_months_has_no_discount(): void
    {
        $quote = PlatformPaybillLicenceService::quoteMonths(2000, 3, 3, 10);

        $this->assertSame(6000.0, $quote['amount']);
        $this->assertSame(0.0, $quote['discount']);
    }

    public function test_more_than_three_months_applies_the_admin_percent(): void
    {
        $quote = PlatformPaybillLicenceService::quoteMonths(2000, 4, 3, 10);

        $this->assertSame(800.0, $quote['discount']);
        $this->assertSame(7200.0, $quote['amount']);
        $this->assertSame(120, $quote['days']);
    }
}
