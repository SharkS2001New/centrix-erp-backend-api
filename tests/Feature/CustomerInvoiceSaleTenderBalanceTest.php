<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CustomerInvoiceSaleTenderBalanceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_mixed_sale_tenders_settle_invoice_when_only_mpesa_was_posted_to_accounting(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $customer = Customer::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $method = PaymentMethod::query()->firstOrFail();

        $sale = Sale::create([
            'order_num' => 1140,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'completed',
            'total_vat' => 13765.50,
            'order_total' => 99800,
            'payment_status' => 'paid',
            'amount_paid' => 99800,
            'payment_method_code' => 'MPESA',
            'cash' => 44800,
            'mpesa_amount' => 55000,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'amount' => 44800,
            'reference_number' => 'CASH',
        ]);
        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'amount' => 55000,
            'reference_number' => 'UBN9U4P219',
        ]);

        $invoice = CustomerInvoice::query()->where('sale_id', $sale->id)->whereNull('deleted_at')->firstOrFail();
        CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $customer->customer_num,
            'payment_method_id' => $method->id,
            'amount_paid' => 55000,
            'date_paid' => now()->toDateString(),
            'received_by' => $admin->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
        ]);

        $service = app(CustomerInvoiceService::class);
        $invoice = $service->settleSaleTendersOntoInvoice($invoice->fresh(), $sale->fresh(), $admin);
        $balances = $service->presentInvoiceBalances($invoice);

        $this->assertEquals(99800.0, $balances['amount_paid']);
        $this->assertEquals(0.0, $balances['balance_due']);
        $this->assertSame(2, $balances['payment_status']);
        $this->assertEquals(99800.0, $service->paidTotalFromPayments($invoice));

        $settlement = $service->settlementsBySaleId([(int) $sale->id])[(int) $sale->id];
        $this->assertEquals(0.0, $settlement['balance_due']);
        $this->assertSame('paid', $settlement['payment_status']);
    }

    public function test_partial_tenders_keep_matching_balance_on_sale_and_invoice(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $customer = Customer::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $method = PaymentMethod::query()->firstOrFail();

        $sale = Sale::create([
            'order_num' => 1141,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'completed',
            'total_vat' => 0,
            'order_total' => 99800,
            'payment_status' => 'paid',
            'amount_paid' => 99800,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'amount' => 55000,
        ]);
        $invoice = CustomerInvoice::query()->where('sale_id', $sale->id)->whereNull('deleted_at')->firstOrFail();
        CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $customer->customer_num,
            'payment_method_id' => $method->id,
            'amount_paid' => 55000,
            'date_paid' => now()->toDateString(),
            'received_by' => $admin->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
        ]);

        $service = app(CustomerInvoiceService::class);
        $balances = $service->presentInvoiceBalances($invoice->fresh());
        $settlement = $service->settlementsBySaleId([(int) $sale->id])[(int) $sale->id];

        $this->assertEquals(55000.0, $balances['amount_paid']);
        $this->assertEquals(44800.0, $balances['balance_due']);
        $this->assertEquals(44800.0, $settlement['balance_due']);
        $this->assertSame('partial', $settlement['payment_status']);
    }

    public function test_accounting_list_shows_zero_balance_for_fully_tendered_mixed_sale(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);
        $customer = Customer::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $method = PaymentMethod::query()->firstOrFail();

        $sale = Sale::create([
            'order_num' => 1142,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'completed',
            'total_vat' => 0,
            'order_total' => 99800,
            'payment_status' => 'paid',
            'amount_paid' => 99800,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'amount' => 44800,
        ]);
        SalePayment::create([
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'amount' => 55000,
        ]);
        $invoice = CustomerInvoice::query()->where('sale_id', $sale->id)->whereNull('deleted_at')->firstOrFail();
        CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $customer->customer_num,
            'payment_method_id' => $method->id,
            'amount_paid' => 55000,
            'date_paid' => now()->toDateString(),
            'received_by' => $admin->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
        ]);

        $row = collect($this->getJson('/api/v1/customer-invoices?per_page=200')->assertOk()->json('data'))
            ->firstWhere('id', $invoice->id);

        $this->assertNotNull($row);
        $this->assertEquals(0.0, (float) $row['balance_due']);
        $this->assertSame(2, (int) $row['payment_status']);
    }
}
