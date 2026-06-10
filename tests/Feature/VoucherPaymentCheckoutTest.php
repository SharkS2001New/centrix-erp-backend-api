<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\User;
use App\Models\Voucher;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class VoucherPaymentCheckoutTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);

        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales']['enable_vouchers'] = true;
        $settings['sales']['enable_redeemable_points'] = true;
        $settings['sales']['point_cash_value'] = 1;
        $settings['sales']['points_earn_per_kes'] = 1000;
        $org->update(['module_settings' => $settings]);
    }

    public function test_payment_voucher_can_partially_pay_checkout(): void
    {
        $productCode = \App\Models\Product::first()->product_code;

        $voucher = Voucher::create([
            'organization_id' => $this->user->organization_id,
            'voucher_code' => 'PAY100',
            'voucher_kind' => 'payment',
            'discount_type' => 'fixed',
            'discount_value' => 100,
            'initial_balance' => 100,
            'balance' => 100,
            'is_active' => true,
        ]);

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $line = $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 2,
        ])->assertCreated()->json();

        $lineTotal = (float) ($line['amount'] ?? 0);
        $this->assertGreaterThan(30, $lineTotal);

        $this->postJson("/api/v1/sales/carts/{$cartId}/payment/voucher", [
            'voucher_code' => 'PAY100',
            'amount' => 30,
        ])->assertOk()->assertJsonPath('voucher.applied_amount', 30);

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
            'pay_now' => max(0, $lineTotal - 30),
        ])->assertCreated()->json();

        $this->assertEquals(30.0, (float) ($sale['voucher_payment_amount'] ?? 0));
        $voucher->refresh();
        $this->assertEquals(70.0, (float) $voucher->balance);
    }

    public function test_loyalty_points_reduce_amount_due(): void
    {
        $productCode = \App\Models\Product::first()->product_code;
        $customer = Customer::first();

        LoyaltyCard::create([
            'organization_id' => $this->user->organization_id,
            'customer_num' => $customer->customer_num,
            'card_number' => 'LC-TEST01',
            'phone_number' => $customer->phone_number ?? '0712345678',
            'points_balance' => 50,
            'is_active' => true,
            'issued_at' => now()->toDateString(),
        ]);

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/payment/points", [
            'phone' => $customer->phone_number ?? '0712345678',
            'points' => 20,
        ])->assertOk()->assertJsonPath('loyalty.applied_amount', 20);

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
        ])->assertCreated()->json();

        $this->assertEquals(20.0, (float) ($sale['points_payment_amount'] ?? 0));
        $this->assertEquals($customer->customer_num, $sale['customer_num']);
    }

    public function test_completed_sale_awards_loyalty_points_to_registered_customer(): void
    {
        $customer = Customer::first();
        $card = LoyaltyCard::create([
            'organization_id' => $this->user->organization_id,
            'customer_num' => $customer->customer_num,
            'card_number' => 'LC-EARN01',
            'phone_number' => $customer->phone_number ?? '0799999999',
            'points_balance' => 10,
            'is_active' => true,
            'issued_at' => now()->toDateString(),
        ]);

        $product = \App\Models\Product::with('unit')->first();
        $unitPrice = (float) ($product->unit_price ?? 100);
        $qty = (int) ceil(5000 / max($unitPrice, 1));

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => $qty,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
            'customer_num' => $customer->customer_num,
        ])->assertCreated();

        $card->refresh();
        $this->assertGreaterThanOrEqual(15, (float) $card->points_balance);
    }

    public function test_loyalty_attach_earns_points_without_redemption(): void
    {
        $customer = Customer::first();
        $card = LoyaltyCard::create([
            'organization_id' => $this->user->organization_id,
            'customer_num' => $customer->customer_num,
            'card_number' => 'LC-EARN02',
            'phone_number' => '0711222333',
            'points_balance' => 0,
            'is_active' => true,
            'issued_at' => now()->toDateString(),
        ]);

        $product = \App\Models\Product::with('unit')->first();
        $unitPrice = (float) ($product->unit_price ?? 100);
        $qty = (int) ceil(5000 / max($unitPrice, 1));

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => $qty,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/loyalty", [
            'phone' => '0711222333',
        ])->assertOk();

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
        ])->assertCreated();

        $card->refresh();
        $this->assertGreaterThanOrEqual(5, (float) $card->points_balance);
    }
}
