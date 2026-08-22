<?php

namespace Tests\Feature;

use App\Models\MpesaIncomingPayment;
use App\Models\MpesaPaybillAccount;
use App\Models\Organization;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Services\Mpesa\MpesaPaybillAccountService;
use App\Services\Mpesa\MpesaPaymentMatchingService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MpesaMultiPaybillTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_c2b_resolves_to_correct_org_by_paybill_account_shortcode(): void
    {
        $orgA = Organization::query()->orderBy('id')->firstOrFail();
        $orgB = Organization::query()->where('id', '!=', $orgA->id)->orderBy('id')->first();
        if (! $orgB) {
            $this->markTestSkipped('Need at least two organizations');
        }

        MpesaPaybillAccount::query()->where('primary_short_code', '9001001')->delete();
        MpesaPaybillAccount::query()->where('primary_short_code', '9002002')->delete();

        MpesaPaybillAccount::query()->create([
            'organization_id' => $orgA->id,
            'name' => 'Org A route paybill',
            'primary_short_code' => '9001001',
            'child_storecode' => '9001001',
            'is_default' => false,
            'is_active' => true,
        ]);
        MpesaPaybillAccount::query()->create([
            'organization_id' => $orgB->id,
            'name' => 'Org B shop paybill',
            'primary_short_code' => '9002002',
            'child_storecode' => '9002002',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/payments/c2b/confirmation', [
            'TransID' => 'MULTIORG001',
            'TransAmount' => '150',
            'BusinessShortCode' => '9001001',
            'MSISDN' => '254711000001',
            'BillRefNumber' => 'S1',
        ])->assertOk();

        $payment = MpesaIncomingPayment::query()->where('transaction_id', 'MULTIORG001')->first();
        $this->assertNotNull($payment);
        $this->assertSame((int) $orgA->id, (int) $payment->organization_id);
        $this->assertNotNull($payment->mpesa_paybill_account_id);
    }

    public function test_matching_rejects_sale_on_different_route_paybill(): void
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

        MpesaPaybillAccount::query()->whereIn('primary_short_code', ['8111001', '8222002'])->delete();

        $payA = MpesaPaybillAccount::query()->create([
            'organization_id' => $org->id,
            'name' => 'Route A paybill',
            'primary_short_code' => '8111001',
            'child_storecode' => '8111001',
            'route_id' => $routeA->id,
            'is_default' => false,
            'is_active' => true,
        ]);
        $payB = MpesaPaybillAccount::query()->create([
            'organization_id' => $org->id,
            'name' => 'Route B paybill',
            'primary_short_code' => '8222002',
            'child_storecode' => '8222002',
            'route_id' => $routeB->id,
            'is_default' => false,
            'is_active' => true,
        ]);

        $routeA->update(['mpesa_paybill_account_id' => $payA->id]);
        $routeB->update(['mpesa_paybill_account_id' => $payB->id]);

        $template = Sale::query()->where('organization_id', $org->id)->orderByDesc('id')->first();
        $this->assertNotNull($template);

        $saleOnB = Sale::query()->create([
            'order_num' => 881122,
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

        $payment = MpesaIncomingPayment::query()->create([
            'organization_id' => $org->id,
            'mpesa_paybill_account_id' => $payA->id,
            'matched_route_id' => $routeA->id,
            'transaction_id' => 'ROUTEMISMATCH1',
            'phone_number' => '0711000000',
            'bill_ref_number' => 'S881122',
            'business_short_code' => '8111001',
            'parsed_order_num' => 881122,
            'amount' => 500,
            'source' => 'c2b',
            'status' => 'available',
            'received_at' => now(),
        ]);

        $service = app(MpesaPaybillAccountService::class);
        $this->assertFalse($service->paymentMatchesSale($payA, '8111001', $saleOnB));

        $candidates = app(MpesaPaymentMatchingService::class)->findCandidates($payment);
        $this->assertSame([], $candidates);
    }
}
