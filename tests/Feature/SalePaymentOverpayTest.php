<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SalePaymentOverpayTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::where('username', 'admin')->firstOrFail();
        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );
    }

    public function test_sale_payment_rejects_amount_above_balance_due(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $sale = Sale::create([
            'order_num' => 880001,
            'branch_id' => $user->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $user->id,
            'status' => 'booked',
            'total_vat' => 0,
            'order_total' => 10000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'is_credit_sale' => 1,
            'stock_balanced' => 1,
        ]);

        $method = PaymentMethod::where('method_code', 'MPESA')->first()
            ?? PaymentMethod::where('method_code', 'CASH')->firstOrFail();

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'payment_method_id' => $method->id,
            'amount' => 12000,
            'reference_number' => 'TESTOVERPAY001',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(0.0, (float) $sale->fresh()->amount_paid);
    }

    public function test_sale_payment_partial_reduces_balance_due(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $sale = Sale::create([
            'order_num' => 880003,
            'branch_id' => $user->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $user->id,
            'status' => 'booked',
            'total_vat' => 0,
            'order_total' => 10000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'is_credit_sale' => 1,
            'stock_balanced' => 1,
        ]);

        $method = PaymentMethod::where('method_code', 'MPESA')->first()
            ?? PaymentMethod::where('method_code', 'CASH')->firstOrFail();

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'payment_method_id' => $method->id,
            'amount' => 4000,
            'reference_number' => 'TESTPARTIAL001',
        ])->assertOk();

        $fresh = $sale->fresh();
        $this->assertEqualsWithDelta(4000.0, (float) $fresh->amount_paid, 0.01);
        $this->assertSame('partial', $fresh->payment_status);
        $this->assertEqualsWithDelta(6000.0, 10000.0 - (float) $fresh->amount_paid, 0.01);
    }

    public function test_sale_payment_accepts_exact_balance_due(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $sale = Sale::create([
            'order_num' => 880002,
            'branch_id' => $user->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $user->id,
            'status' => 'booked',
            'total_vat' => 0,
            'order_total' => 10000,
            'payment_status' => 'partial',
            'amount_paid' => 2500,
            'is_credit_sale' => 1,
            'stock_balanced' => 1,
        ]);

        $method = PaymentMethod::where('method_code', 'MPESA')->first()
            ?? PaymentMethod::where('method_code', 'CASH')->firstOrFail();

        $this->postJson("/api/v1/sales/{$sale->id}/payments", [
            'payment_method_id' => $method->id,
            'amount' => 7500,
            'reference_number' => 'TESTEXACT001',
        ])->assertOk();

        $fresh = $sale->fresh();
        $this->assertEqualsWithDelta(10000.0, (float) $fresh->amount_paid, 0.01);
        $this->assertSame('paid', $fresh->payment_status);
    }
}
