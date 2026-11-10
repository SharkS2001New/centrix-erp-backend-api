<?php

namespace Tests\Unit;

use App\Models\PaymentMethod;
use App\Services\Sales\OrgPaymentMethodFilter;
use PHPUnit\Framework\TestCase;

class OrgPaymentMethodFilterTest extends TestCase
{
    public function test_filters_to_recording_payments_toggles(): void
    {
        $methods = collect([
            $this->method('CASH', 'Cash'),
            $this->method('MPESA', 'M-Pesa'),
            $this->method('EQUITY', 'Equity'),
            $this->method('KCB', 'KCB'),
            $this->method('BANK', 'Bank'),
            $this->method('CHEQUE', 'Cheque'),
            $this->method('CARD', 'Card'),
            $this->method('CREDIT', 'Credit'),
            $this->method('VOUCHER', 'Voucher'),
            $this->method('POINTS', 'Points'),
        ]);

        $sales = [
            'enable_mpesa_amount' => true,
            'enable_equity_bank' => true,
            'enable_kcb_bank' => false,
            'enable_other_bank' => false,
            'enable_cheque' => true,
            'enable_credit_payment' => true,
        ];

        $codes = OrgPaymentMethodFilter::filter($methods, $sales, [
            'include_credit' => true,
        ])->pluck('method_code')->all();

        $this->assertSame(['CASH', 'MPESA', 'EQUITY', 'CHEQUE', 'CREDIT'], $codes);
    }

    public function test_platform_gates_can_disable_mpesa_and_equity(): void
    {
        $methods = collect([
            $this->method('CASH', 'Cash'),
            $this->method('MPESA', 'M-Pesa'),
            $this->method('EQUITY', 'Equity'),
        ]);

        $codes = OrgPaymentMethodFilter::filter($methods, [
            'enable_mpesa_amount' => true,
            'enable_equity_bank' => true,
        ], [
            'mpesa_platform' => false,
            'equity_platform' => false,
        ])->pluck('method_code')->all();

        $this->assertSame(['CASH'], $codes);
    }

    private function method(string $code, string $name): PaymentMethod
    {
        $method = new PaymentMethod;
        $method->forceFill([
            'method_code' => $code,
            'method_name' => $name,
            'is_active' => true,
        ]);

        return $method;
    }
}
