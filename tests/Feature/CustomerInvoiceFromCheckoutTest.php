<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CustomerInvoiceFromCheckoutTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_checkout_creates_customer_invoice_for_registered_customer_even_when_fully_paid(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);
        $customer = Customer::firstOrFail();
        $product = Product::firstOrFail();

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $admin->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $lineTotal = (float) $this->getJson("/api/v1/sales/carts/{$cartId}")
            ->json('lines.0.amount');

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'customer_num' => $customer->customer_num,
            'pay_now' => $lineTotal,
            'payment_method_code' => 'CASH',
        ])->assertCreated()->json();

        $this->assertDatabaseHas('customer_invoices', [
            'sale_id' => $sale['id'],
            'customer_num' => $customer->customer_num,
            'payment_status' => 2,
        ]);
    }

    public function test_mobile_checkout_defers_invoice_until_after_response_then_creates_it(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);
        $customer = Customer::firstOrFail();
        $product = Product::firstOrFail();

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $admin->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 250,
            'on_wholesale_retail' => 0,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'customer_num' => $customer->customer_num,
            'payment_method_code' => 'CASH',
        ])->assertCreated()->json();

        $this->assertArrayHasKey('id', $sale);
        $this->assertArrayNotHasKey('items', $sale);

        // afterResponse finalize should have created the invoice + cleared the flag.
        $this->assertDatabaseHas('customer_invoices', [
            'sale_id' => $sale['id'],
            'customer_num' => $customer->customer_num,
        ]);

        $persisted = \App\Models\Sale::query()->findOrFail($sale['id']);
        $meta = is_array($persisted->fulfillment_meta) ? $persisted->fulfillment_meta : [];
        $this->assertArrayNotHasKey('mobile_deferred_ar', $meta);
    }

    public function test_accounting_user_can_list_customer_invoices_without_payments_module(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/customer-invoices')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }
}
