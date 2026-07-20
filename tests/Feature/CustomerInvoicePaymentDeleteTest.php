<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use App\Services\Fulfillment\TripAutoCloseService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CustomerInvoicePaymentDeleteTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function ensureActiveSubscription(User $user): void
    {
        $org = Organization::query()->find($user->organization_id);
        if (! $org) {
            return;
        }

        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $org->id],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
    }

    public function test_customer_invoice_payment_can_be_deleted_and_balances_are_restored(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureActiveSubscription($admin);
        Sanctum::actingAs($admin);

        $customer = Customer::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $method = PaymentMethod::query()->firstOrFail();

        $sale = Sale::create([
            'order_num' => 92001,
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

        $invoice = CustomerInvoice::query()->where('sale_id', $sale->id)->firstOrFail();

        $payment = CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $customer->customer_num,
            'payment_method_id' => $method->id,
            'amount_paid' => 400,
            'date_paid' => now()->toDateString(),
            'received_by' => $admin->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
        ]);

        $invoice->refresh();
        app(TripAutoCloseService::class)->syncSaleAfterAccountingPayment(
            $sale,
            $admin,
            400,
            (float) $invoice->amount_paid,
            $method->id,
        );

        $invoice->refresh();
        $sale->refresh();

        $this->assertEquals(400.0, (float) $invoice->amount_paid);
        $this->assertSame(1, (int) $invoice->payment_status);
        $this->assertEquals(400.0, (float) $sale->amount_paid);
        $this->assertSame('partial', $sale->payment_status);

        $this->deleteJson("/api/v1/customer-invoice-payments/{$payment->id}")
            ->assertNoContent();

        $invoice->refresh();
        $sale->refresh();
        $customer->refresh();

        $this->assertEquals(0.0, (float) $invoice->amount_paid);
        $this->assertSame(0, (int) $invoice->payment_status);
        $this->assertEquals(0.0, (float) $sale->amount_paid);
        $this->assertSame('unpaid', $sale->payment_status);
        $this->assertGreaterThan(0, (float) $customer->current_balance);
    }

    public function test_repaying_after_delete_does_not_overpay_when_sale_amount_is_inflated(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureActiveSubscription($admin);
        Sanctum::actingAs($admin);

        $customer = Customer::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $method = PaymentMethod::query()->firstOrFail();

        $sale = Sale::create([
            'order_num' => 92002,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 25125,
            'payment_status' => 'paid',
            'amount_paid' => 30125,
        ]);

        $invoice = CustomerInvoice::query()->where('sale_id', $sale->id)->firstOrFail();
        $invoice->update([
            'invoice_total' => 25125,
            'amount_paid' => 30125,
            'payment_status' => 2,
        ]);

        $overpayment = CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $customer->customer_num,
            'payment_method_id' => $method->id,
            'amount_paid' => 5000,
            'date_paid' => now()->toDateString(),
            'received_by' => $admin->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
        ]);

        $original = CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $customer->customer_num,
            'payment_method_id' => $method->id,
            'amount_paid' => 20125,
            'date_paid' => now()->subDays(4)->toDateString(),
            'received_by' => $admin->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
        ]);

        $this->deleteJson("/api/v1/customer-invoice-payments/{$overpayment->id}")
            ->assertNoContent();

        $invoice->refresh();
        $this->assertEquals(20125.0, (float) $invoice->amount_paid);
        $this->assertEquals(5000.0, app(\App\Services\Accounting\CustomerInvoiceService::class)->balanceDueFromPayments($invoice));

        $this->postJson('/api/v1/customer-invoice-payments', [
            'customer_invoice_id' => $invoice->id,
            'payment_method_id' => $method->id,
            'amount_paid' => 5000,
            'date_paid' => now()->toDateString(),
        ])->assertCreated();

        $invoice->refresh();
        $sale->refresh();

        $paidFromPayments = (float) CustomerInvoicePayment::query()
            ->where('customer_invoice_id', $invoice->id)
            ->sum('amount_paid');

        $this->assertEquals(25125.0, $paidFromPayments);
        $this->assertEquals(25125.0, (float) $invoice->amount_paid);
        $this->assertEquals(25125.0, (float) $sale->amount_paid);
        $this->assertSame(2, (int) $invoice->payment_status);
        $this->assertNotNull(CustomerInvoicePayment::query()->find($original->id));
    }

    public function test_accounts_receivable_summary_matches_payment_totals_after_delete_and_repay(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureActiveSubscription($admin);
        Sanctum::actingAs($admin);

        $customer = Customer::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $method = PaymentMethod::query()->firstOrFail();

        $sale = Sale::create([
            'order_num' => 92003,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 25125,
            'payment_status' => 'paid',
            'amount_paid' => 30125,
        ]);

        $invoice = CustomerInvoice::query()->where('sale_id', $sale->id)->firstOrFail();
        $invoice->update(['invoice_total' => 25125, 'amount_paid' => 30125, 'payment_status' => 2]);

        $overpayment = CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $customer->customer_num,
            'payment_method_id' => $method->id,
            'amount_paid' => 5000,
            'date_paid' => now()->toDateString(),
            'received_by' => $admin->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
        ]);

        CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $customer->customer_num,
            'payment_method_id' => $method->id,
            'amount_paid' => 20125,
            'date_paid' => now()->subDays(4)->toDateString(),
            'received_by' => $admin->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
        ]);

        $this->deleteJson("/api/v1/customer-invoice-payments/{$overpayment->id}")
            ->assertNoContent();

        $this->postJson('/api/v1/customer-invoice-payments', [
            'customer_invoice_id' => $invoice->id,
            'payment_method_id' => $method->id,
            'amount_paid' => 5000,
            'date_paid' => now()->toDateString(),
        ])->assertCreated();

        $invoiceResponse = $this->getJson("/api/v1/customer-invoices/{$invoice->id}")
            ->assertOk()
            ->json();

        $this->assertEquals(25125.0, (float) $invoiceResponse['amount_paid']);
        $this->assertEquals(0.0, (float) $invoiceResponse['balance_due']);

        $paidFromPayments = (float) CustomerInvoicePayment::query()
            ->where('customer_invoice_id', $invoice->id)
            ->sum('amount_paid');
        $this->assertEquals(25125.0, $paidFromPayments);

        $aging = $this->getJson('/api/v1/reports/ar-aging', [
            'customer_num' => $customer->customer_num,
            'filter' => ['customer_num' => $customer->customer_num],
        ])->assertOk()->json();

        $agingRow = collect($aging['data'] ?? [])
            ->first(fn ($row) => ($row['invoice_number'] ?? null) === $invoice->invoice_number);

        $this->assertNull($agingRow, 'Fully paid invoice should not appear in AR aging.');
    }
}
