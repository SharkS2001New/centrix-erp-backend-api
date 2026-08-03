<?php

namespace Tests\Unit;

use App\Services\Erp\SalePaymentColumnMapper;
use PHPUnit\Framework\TestCase;

class SalePaymentColumnMapperTest extends TestCase
{
    public function test_increments_for_equity_and_cash(): void
    {
        $this->assertSame(['equity_amount' => 10000.0], SalePaymentColumnMapper::incrementsForMethod('EQUITY', 10000));
        $this->assertSame(['cash' => 500.0], SalePaymentColumnMapper::incrementsForMethod('CASH', 500));
        $this->assertSame([], SalePaymentColumnMapper::incrementsForMethod('EQUITY', 0));
    }
}
