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
}
