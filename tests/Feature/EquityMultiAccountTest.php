<?php

namespace Tests\Feature;

use App\Models\EquityBankAccount;
use App\Models\EquityIncomingPayment;
use App\Models\Organization;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Services\Equity\EquityBankAccountService;
use App\Services\Equity\EquityPaymentMatchingService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class EquityMultiAccountTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_callback_resolves_to_correct_org_by_account_number(): void
    {
        $orgA = Organization::query()->orderBy('id')->firstOrFail();
        $orgB = Organization::query()->where('id', '!=', $orgA->id)->orderBy('id')->first();
        if (! $orgB) {
            $this->markTestSkipped('Need at least two organizations');
        }

        EquityBankAccount::query()->where('primary_account_number', 'EQ9001001')->delete();
        EquityBankAccount::query()->where('primary_account_number', 'EQ9002002')->delete();

        EquityBankAccount::query()->create([
            'organization_id' => $orgA->id,
            'name' => 'Org A route Equity',
            'primary_account_number' => 'EQ9001001',
            'paybill_number' => 'EQ9001001',
            'is_default' => false,
            'is_active' => true,
        ]);
        EquityBankAccount::query()->create([
            'organization_id' => $orgB->id,
            'name' => 'Org B shop Equity',
            'primary_account_number' => 'EQ9002002',
            'paybill_number' => 'EQ9002002',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/payments/equity/confirmation', [
            'TransID' => 'EQMULTIORG001',
            'TransAmount' => '150',
            'BusinessShortCode' => 'EQ9001001',
            'MSISDN' => '254711000001',
            'BillRefNumber' => 'S1',
        ])->assertOk();

        $payment = EquityIncomingPayment::query()->where('transaction_id', 'EQMULTIORG001')->first();
        $this->assertNotNull($payment);
        $this->assertSame((int) $orgA->id, (int) $payment->organization_id);
        $this->assertNotNull($payment->equity_bank_account_id);
    }

    public function test_matching_rejects_sale_on_different_route_equity_account(): void
    {
        $org = Organization::query()->orderBy('id')->firstOrFail();
        $routeA = RouteModel::query()->where('organization_id', $org->id)->orderBy('id')->first();
        $routeB = RouteModel::query()
            ->where('organization_id', $org->id)
            ->when($routeA, fn ($q) => $q->where('id', '!=', $routeA->id))
            ->orderBy('id')
            ->first();
        if (! $routeA || ! $routeB) {
            $this->markTestSkipped('Need two routes in the demo org');
        }

        EquityBankAccount::query()->whereIn('primary_account_number', ['EQ8111001', 'EQ8222002'])->delete();

        $payA = EquityBankAccount::query()->create([
            'organization_id' => $org->id,
            'name' => 'Route A Equity',
            'primary_account_number' => 'EQ8111001',
            'paybill_number' => 'EQ8111001',
            'route_id' => $routeA->id,
            'is_default' => false,
            'is_active' => true,
        ]);
        $payB = EquityBankAccount::query()->create([
            'organization_id' => $org->id,
            'name' => 'Route B Equity',
            'primary_account_number' => 'EQ8222002',
            'paybill_number' => 'EQ8222002',
            'route_id' => $routeB->id,
            'is_default' => false,
            'is_active' => true,
        ]);

        $routeA->update(['equity_bank_account_id' => $payA->id]);
        $routeB->update(['equity_bank_account_id' => $payB->id]);

        $template = Sale::query()->where('organization_id', $org->id)->orderByDesc('id')->first();
        $this->assertNotNull($template);

        $saleOnB = Sale::query()->create([
            'order_num' => 991122,
            'branch_id' => $template->branch_id,
            'organization_id' => $org->id,
            'channel' => 'mobile',
            'cashier_id' => $template->cashier_id,
            'customer_num' => $template->customer_num,
            'route_id' => $routeB->id,
            'status' => 'unpaid',
            'total_vat' => 0,
            'order_total' => 500,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $payment = EquityIncomingPayment::query()->create([
            'organization_id' => $org->id,
            'equity_bank_account_id' => $payA->id,
            'matched_route_id' => $routeA->id,
            'transaction_id' => 'EQROUTEMISMATCH1',
            'phone_number' => '0711000000',
            'bill_ref_number' => 'S991122',
            'business_account_number' => 'EQ8111001',
            'parsed_order_num' => 991122,
            'amount' => 500,
            'source' => 'callback',
            'status' => 'available',
            'received_at' => now(),
        ]);

        $service = app(EquityBankAccountService::class);
        $this->assertFalse($service->paymentMatchesSale($payA, 'EQ8111001', $saleOnB));

        $candidates = app(EquityPaymentMatchingService::class)->findCandidates($payment);
        $this->assertSame([], $candidates);
    }
}
