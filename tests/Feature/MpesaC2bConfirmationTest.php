<?php

namespace Tests\Feature;

use App\Models\MpesaIncomingPayment;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MpesaC2bConfirmationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_c2b_confirmation_stores_incoming_payment_for_check_payment(): void
    {
        $this->postJson('/api/v1/payments/c2b/confirmation', [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'QBC1234567',
            'TransTime' => '20260603120000',
            'TransAmount' => '250.00',
            'BusinessShortCode' => '6563610',
            'BillRefNumber' => '',
            'MSISDN' => '254712345678',
            'FirstName' => 'Jane',
        ])
            ->assertOk()
            ->assertJson([
                'ResultCode' => 0,
                'ResultDesc' => 'Success',
            ]);

        $payment = MpesaIncomingPayment::query()->where('transaction_id', 'QBC1234567')->first();
        $this->assertNotNull($payment);
        $this->assertSame('0712345678', $payment->phone_number);
        $this->assertSame(250, (int) $payment->amount);
        $this->assertSame('c2b', $payment->source);
        $this->assertSame('available', $payment->status);
    }

    public function test_c2b_confirmation_is_idempotent_for_duplicate_trans_id(): void
    {
        $payload = [
            'TransID' => 'QBC9999999',
            'TransAmount' => '100',
            'MSISDN' => '254700000001',
        ];

        $this->postJson('/api/v1/payments/c2b/confirmation', $payload)->assertOk();
        $this->postJson('/api/v1/payments/c2b/confirmation', $payload)->assertOk();

        $this->assertSame(1, MpesaIncomingPayment::query()->where('transaction_id', 'QBC9999999')->count());
    }
}
