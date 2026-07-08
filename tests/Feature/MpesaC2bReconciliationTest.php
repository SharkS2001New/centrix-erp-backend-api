<?php

namespace Tests\Feature;

use App\Jobs\ProcessMpesaIncomingMatchJob;
use App\Models\MpesaIncomingPayment;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MpesaC2bReconciliationTest extends TestCase
{
    use RefreshesErpDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::query()->orderBy('id')->firstOrFail();
        $settings = $this->organization->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'mpesa' => array_merge(config('erp.module_settings_defaults.finance.mpesa', []), [
                'env' => 'live',
                'enable_c2b_reconciliation' => true,
                'auto_apply_order_reference' => true,
                'child_storecode' => '6563610',
                'till_number' => '6563610',
            ]),
        ]);
        $this->organization->update(['module_settings' => $settings]);
    }

    public function test_c2b_confirmation_stores_bill_reference(): void
    {
        $this->postJson('/api/v1/payments/c2b/confirmation', [
            'TransID' => 'QBC-REF-001',
            'TransAmount' => '250.00',
            'MSISDN' => '254712345678',
            'BusinessShortCode' => '6563610',
            'BillRefNumber' => 'S12',
            'FirstName' => 'Jane',
        ])->assertOk();

        $payment = MpesaIncomingPayment::query()->where('transaction_id', 'QBC-REF-001')->first();
        $this->assertNotNull($payment);
        $this->assertSame('S12', $payment->bill_ref_number);
        $this->assertSame('Jane', $payment->payer_name);
        $this->assertSame(12, (int) $payment->parsed_order_num);
    }

    public function test_auto_match_applies_payment_to_unpaid_sale(): void
    {
        Queue::fake();

        $sale = Sale::query()->where('organization_id', $this->organization->id)->firstOrFail();
        $sale->update([
            'order_num' => 77,
            'order_total' => 500,
            'amount_paid' => 0,
            'payment_status' => 'unpaid',
            'status' => 'confirmed',
        ]);

        $this->postJson('/api/v1/payments/c2b/confirmation', [
            'TransID' => 'QBC-AUTO-001',
            'TransAmount' => '500',
            'MSISDN' => '254712345678',
            'BusinessShortCode' => '6563610',
            'BillRefNumber' => 'S77',
        ])->assertOk();

        $payment = MpesaIncomingPayment::query()->where('transaction_id', 'QBC-AUTO-001')->firstOrFail();
        Queue::assertPushed(ProcessMpesaIncomingMatchJob::class, fn ($job) => $job->paymentId === $payment->id);

        (new ProcessMpesaIncomingMatchJob($payment->id))->handle(
            app(\App\Services\Mpesa\MpesaPaymentMatchingService::class),
            app(\App\Services\Mpesa\MpesaPaymentApplicationService::class),
        );

        $payment->refresh();
        $sale->refresh();
        $this->assertSame('applied', $payment->status);
        $this->assertSame((int) $sale->id, (int) $payment->applied_sale_id);
        $this->assertSame(500.0, (float) $sale->amount_paid);
    }

    public function test_reconciliation_index_reports_disabled_state(): void
    {
        $user = User::query()->where('organization_id', $this->organization->id)->firstOrFail();
        $settings = $this->organization->module_settings;
        $settings['finance']['mpesa']['enable_c2b_reconciliation'] = false;
        $this->organization->update(['module_settings' => $settings]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/accounting/mpesa-reconciliation')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('payments', [])
            ->assertJsonPath('summary.count', 0);
    }

    public function test_accountant_can_manually_apply_unmatched_payment(): void
    {
        $user = User::query()->where('organization_id', $this->organization->id)->where('is_admin', true)->firstOrFail();
        $sale = Sale::query()->where('organization_id', $this->organization->id)->firstOrFail();
        $sale->update([
            'order_num' => 91,
            'order_total' => 300,
            'amount_paid' => 0,
            'payment_status' => 'unpaid',
            'status' => 'confirmed',
        ]);

        $payment = MpesaIncomingPayment::query()->create([
            'organization_id' => $this->organization->id,
            'transaction_id' => 'MANUAL-001',
            'phone_number' => '0712345678',
            'amount' => 300,
            'source' => 'c2b',
            'status' => 'available',
            'reconciliation_status' => 'unmatched',
            'received_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/accounting/mpesa-reconciliation/{$payment->id}/apply", [
                'sale_id' => $sale->id,
            ])
            ->assertOk()
            ->assertJsonPath('payment.status', 'applied');

        $payment->refresh();
        $sale->refresh();
        $this->assertSame((int) $sale->id, (int) $payment->applied_sale_id);
        $this->assertSame(300.0, (float) $sale->amount_paid);
    }
}
