<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CustomerReceivablePaymentTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_customer_payment_allocates_fifo_across_open_invoices(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $customer = Customer::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $method = PaymentMethod::query()->firstOrFail();

        $saleA = Sale::create([
            'order_num' => 91001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 1000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $saleB = Sale::create([
            'order_num' => 91002,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 500,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $invoiceA = CustomerInvoice::query()->where('sale_id', $saleA->id)->firstOrFail();
        $invoiceB = CustomerInvoice::query()->where('sale_id', $saleB->id)->firstOrFail();

        $response = $this->postJson("/api/v1/customers/{$customer->customer_num}/payments", [
            'payment_method_id' => $method->id,
            'amount_paid' => 1200,
            'date_paid' => now()->toDateString(),
            'notes' => 'Customer collect payment test',
        ]);

        $response->assertCreated()
            ->assertJsonPath('amount_applied', 1200);

        $invoiceA->refresh();
        $invoiceB->refresh();
        $saleA->refresh();
        $saleB->refresh();
        $customer->refresh();

        $this->assertSame(2, (int) $invoiceA->payment_status);
        $this->assertEquals(1000.0, (float) $invoiceA->amount_paid);
        $this->assertSame(1, (int) $invoiceB->payment_status);
        $this->assertEquals(200.0, (float) $invoiceB->amount_paid);
        $this->assertEquals(1000.0, (float) $saleA->amount_paid);
        $this->assertEquals(200.0, (float) $saleB->amount_paid);
        $this->assertEquals(300.0, (float) $customer->current_balance);
    }

    public function test_customer_payment_can_target_a_single_invoice(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $customer = Customer::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $method = PaymentMethod::query()->firstOrFail();

        $sale = Sale::create([
            'order_num' => 91003,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 800,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $invoice = CustomerInvoice::query()->where('sale_id', $sale->id)->firstOrFail();

        $this->postJson("/api/v1/customers/{$customer->customer_num}/payments", [
            'payment_method_id' => $method->id,
            'amount_paid' => 300,
            'customer_invoice_id' => $invoice->id,
        ])->assertCreated()->assertJsonPath('amount_applied', 300);

        $invoice->refresh();
        $sale->refresh();

        $this->assertSame(1, (int) $invoice->payment_status);
        $this->assertEquals(300.0, (float) $invoice->amount_paid);
        $this->assertEquals(300.0, (float) $sale->amount_paid);
    }
}
