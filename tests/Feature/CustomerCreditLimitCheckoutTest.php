<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CustomerCreditLimitCheckoutTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_credit_checkout_requires_registered_customer(): void
    {
        $productCode = Product::first()->product_code;

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'is_credit_sale' => true,
            'pay_now' => 0,
            'payment_method_code' => 'CREDIT',
        ])->assertStatus(422);
    }

    public function test_credit_checkout_blocked_when_exceeding_limit(): void
    {
        $customer = Customer::firstOrFail();
        $customer->update([
            'credit_limit' => 1000,
            'current_balance' => 0,
        ]);

        CustomerInvoice::create([
            'invoice_number' => 'AR-TEST-LIMIT-1',
            'sale_id' => 1,
            'customer_num' => $customer->customer_num,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $this->user->organization_id,
            'created_by' => $this->user->id,
            'invoice_date' => now()->toDateString(),
            'total_vat' => 0,
            'invoice_total' => 800,
            'amount_paid' => 0,
            'payment_status' => 0,
        ]);

        $productCode = Product::first()->product_code;

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $line = $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 3,
        ])->assertCreated()->json();

        $lineTotal = (float) ($line['amount'] ?? 0);
        $this->assertGreaterThan(200, $lineTotal);

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'is_credit_sale' => true,
            'pay_now' => 0,
            'payment_method_code' => 'CREDIT',
            'customer_num' => $customer->customer_num,
        ])->assertStatus(422);
    }

    public function test_credit_checkout_allowed_within_limit(): void
    {
        $customer = Customer::firstOrFail();
        $customer->update([
            'credit_limit' => 50000,
            'current_balance' => 0,
        ]);

        $productCode = Product::first()->product_code;

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'is_credit_sale' => true,
            'pay_now' => 0,
            'payment_method_code' => 'CREDIT',
            'customer_num' => $customer->customer_num,
        ])
            ->assertCreated()
            ->assertJsonPath('customer_num', $customer->customer_num)
            ->assertJsonPath('customer_name_override', $customer->customer_name)
            ->assertJsonPath('is_credit_sale', 1);
    }

    public function test_mobile_checkout_stores_customer_name_from_registered_customer(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $customer = Customer::firstOrFail();
        $productCode = Product::first()->product_code;

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $user->branch_id,
        ])->json('id');

        $line = $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ])->assertCreated()->json();

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'customer_num' => $customer->customer_num,
            'payment_method_code' => 'CASH',
            'pay_now' => (float) ($line['amount'] ?? 100),
        ])
            ->assertCreated()
            ->assertJsonPath('customer_num', $customer->customer_num)
            ->assertJsonPath('customer_name_override', $customer->customer_name);
    }
}
