<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Till;
use App\Models\TillFloatSession;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class TillSessionFlowTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;
    protected string $productCode;
    protected Till $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);

        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->productCode = Product::first()->product_code;
        $this->till = Till::firstOrFail();
        Sanctum::actingAs($this->user);

        $this->enableRequirePosTillFloat();
        $this->closeExistingOpenSessions();
    }

    protected function enableRequirePosTillFloat(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'require_pos_till_float' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function closeExistingOpenSessions(): void
    {
        TillFloatSession::query()
            ->where('status', 'open')
            ->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);
    }

    protected function clearCashierSessionsForToday(): void
    {
        // Move any same-day sessions off today so openFreshSession can create a new one in tests.
        TillFloatSession::query()
            ->where('cashier_id', $this->user->id)
            ->whereDate('session_date', now()->toDateString())
            ->update([
                'status' => 'closed',
                'closed_at' => now(),
                'session_date' => now()->subDay()->toDateString(),
            ]);
    }

    protected function openFreshSession(float $float = 5000): TillFloatSession
    {
        $this->clearCashierSessionsForToday();

        $response = $this->postJson('/api/v1/pos/sessions/open', [
            'till_id' => $this->till->id,
            'branch_id' => $this->user->branch_id,
            'working_amount' => $float,
            'payment_type' => 'CASH',
        ])->assertCreated();

        return TillFloatSession::findOrFail($response->json('id'));
    }

    public function test_checkout_without_session_rejected_when_float_required(): void
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
            'till_id' => $this->till->id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'payment_method_code' => 'CASH',
        ])->assertStatus(422);
    }

    public function test_open_checkout_x_close_z_flow_records_cash_sales(): void
    {
        $session = $this->openFreshSession(5000);

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
            'till_id' => $this->till->id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 2,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'payment_method_code' => 'CASH',
            'float_session_id' => $session->id,
        ])->assertCreated()->json();

        $this->assertEquals('completed', $sale['status']);
        $this->assertGreaterThan(0, (float) $sale['cash']);

        $xReport = $this->getJson("/api/v1/pos/sessions/{$session->id}/x-report")
            ->assertOk()
            ->json();

        $this->assertSame('X', $xReport['type']);
        $this->assertGreaterThan(0, (float) ($xReport['report']['sales']['cash'] ?? 0));
        $this->assertGreaterThan(
            5000,
            (float) ($xReport['report']['expected_cash'] ?? 0),
        );

        $close = $this->postJson("/api/v1/pos/sessions/{$session->id}/close", [
            'closing_amount' => $xReport['report']['expected_cash'],
        ])->assertOk()->json();

        $this->assertEqualsWithDelta(0, (float) $close['variance'], 0.01);

        $this->getJson("/api/v1/pos/sessions/{$session->id}/z-report")
            ->assertOk()
            ->assertJsonPath('type', 'Z');

        $this->assertDatabaseHas('sales', [
            'id' => $sale['id'],
            'float_session_id' => $session->id,
        ]);
    }

    public function test_cash_movement_adjusts_expected_cash_on_x_report(): void
    {
        $session = $this->openFreshSession(3000);

        $this->postJson("/api/v1/pos/sessions/{$session->id}/cash-movement", [
            'type' => 'drop',
            'amount' => 500,
            'reason' => 'Safe drop',
        ])->assertOk();

        $xReport = $this->getJson("/api/v1/pos/sessions/{$session->id}/x-report")
            ->assertOk()
            ->json();

        $this->assertEqualsWithDelta(
            2500,
            (float) ($xReport['report']['expected_cash'] ?? 0),
            0.01,
        );
    }

    public function test_suspend_and_resume_session(): void
    {
        $session = $this->openFreshSession(2000);

        $this->postJson("/api/v1/pos/sessions/{$session->id}/suspend")
            ->assertOk()
            ->assertJsonPath('status', 'suspended');

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
            'till_id' => $this->till->id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'payment_method_code' => 'CASH',
            'float_session_id' => $session->id,
        ])->assertStatus(422);

        $this->postJson("/api/v1/pos/sessions/{$session->id}/resume")
            ->assertOk()
            ->assertJsonPath('status', 'open');
    }

    public function test_handover_session_changes_cashier(): void
    {
        $session = $this->openFreshSession(1500);
        $other = User::where('username', 'cashier')->first();
        $this->assertNotNull($other);

        $this->postJson("/api/v1/pos/sessions/{$session->id}/handover", [
            'to_cashier_id' => $other->id,
            'notes' => 'Break coverage',
        ])->assertOk()
            ->assertJsonPath('to_cashier_id', $other->id);

        $session->refresh();
        $this->assertSame($other->id, (int) $session->cashier_id);
    }

    public function test_debtor_payment_collected_in_session_appears_on_x_report(): void
    {
        $session = $this->openFreshSession(5000);

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
            'till_id' => $this->till->id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'payment_method_code' => 'CREDIT',
            'is_credit_sale' => true,
            'float_session_id' => $session->id,
            'sales_workspace' => 'pos',
        ]);

        if ($sale->status() === 422) {
            $this->markTestSkipped('Credit checkout not enabled for demo org.');
        }

        $saleId = $sale->assertCreated()->json('id');
        $cashMethod = PaymentMethod::where('method_code', 'CASH')->firstOrFail();

        $this->postJson("/api/v1/sales/{$saleId}/payments", [
            'payment_method_id' => $cashMethod->id,
            'amount' => 1500,
            'float_session_id' => $session->id,
        ])->assertOk();

        $xReport = $this->getJson("/api/v1/pos/sessions/{$session->id}/x-report")
            ->assertOk()
            ->json('report');

        $this->assertGreaterThanOrEqual(
            1500,
            (float) ($xReport['sales']['debtor_collections'] ?? 0),
        );
        $this->assertEqualsWithDelta(
            6500,
            (float) ($xReport['till']['gross_total'] ?? 0),
            1.0,
        );
    }

    public function test_x_report_payment_summary_uses_sales_column_splits(): void
    {
        $session = $this->openFreshSession(5000);
        $cashMethod = PaymentMethod::where('method_code', 'CASH')->firstOrFail();

        $sale = Sale::create([
            'order_num' => 990001,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $this->user->organization_id,
            'channel' => 'pos',
            'till_id' => $this->till->id,
            'float_session_id' => $session->id,
            'cashier_id' => $this->user->id,
            'status' => 'completed',
            'order_total' => 1000,
            'total_vat' => 0,
            'amount_paid' => 1000,
            'payment_status' => 'paid',
            'cash' => 400,
            'mpesa_amount' => 300,
            'equity_amount' => 300,
            'completed_at' => now(),
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'float_session_id' => $session->id,
            'payment_method_id' => $cashMethod->id,
            'amount' => 1000,
        ]);

        $payments = collect(
            $this->getJson("/api/v1/pos/sessions/{$session->id}/x-report")
                ->assertOk()
                ->json('report.payments') ?? [],
        )->keyBy('method_code');

        $this->assertEqualsWithDelta(400, (float) ($payments['CASH']['total'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(300, (float) ($payments['MPESA']['total'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(300, (float) ($payments['EQUITY']['total'] ?? 0), 0.01);
    }

    public function test_x_report_payment_summary_groups_sale_payments_by_method(): void
    {
        $session = $this->openFreshSession(5000);
        $cashMethod = PaymentMethod::where('method_code', 'CASH')->firstOrFail();
        $mpesaMethod = PaymentMethod::where('method_code', 'MPESA')->firstOrFail();
        $equityMethod = PaymentMethod::where('method_code', 'EQUITY')->firstOrFail();

        $sale = Sale::create([
            'order_num' => 990002,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $this->user->organization_id,
            'channel' => 'pos',
            'till_id' => $this->till->id,
            'float_session_id' => $session->id,
            'cashier_id' => $this->user->id,
            'status' => 'completed',
            'order_total' => 1000,
            'total_vat' => 0,
            'amount_paid' => 1000,
            'payment_status' => 'paid',
            'completed_at' => now(),
        ]);

        foreach ([
            [$cashMethod->id, 400],
            [$mpesaMethod->id, 300],
            [$equityMethod->id, 300],
        ] as [$methodId, $amount]) {
            SalePayment::create([
                'sale_id' => $sale->id,
                'float_session_id' => $session->id,
                'payment_method_id' => $methodId,
                'amount' => $amount,
            ]);
        }

        $payments = collect(
            $this->getJson("/api/v1/pos/sessions/{$session->id}/x-report")
                ->assertOk()
                ->json('report.payments') ?? [],
        )->keyBy('method_code');

        $this->assertEqualsWithDelta(400, (float) ($payments['CASH']['total'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(300, (float) ($payments['MPESA']['total'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(300, (float) ($payments['EQUITY']['total'] ?? 0), 0.01);

        $this->postJson("/api/v1/pos/sessions/{$session->id}/close", [
            'closing_amount' => 5000,
        ])->assertOk();

        $zPayments = collect(
            $this->getJson("/api/v1/pos/sessions/{$session->id}/z-report")
                ->assertOk()
                ->assertJsonPath('type', 'Z')
                ->json('report.payments') ?? [],
        )->keyBy('method_code');

        $this->assertEqualsWithDelta(400, (float) ($zPayments['CASH']['total'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(300, (float) ($zPayments['MPESA']['total'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(300, (float) ($zPayments['EQUITY']['total'] ?? 0), 0.01);
    }

    public function test_till_float_session_crud_store_is_blocked(): void
    {
        $this->postJson('/api/v1/till-float-sessions', [
            'till_id' => $this->till->id,
            'branch_id' => $this->user->branch_id,
            'cashier_id' => $this->user->id,
            'session_date' => now()->toDateString(),
            'working_amount' => 1000,
        ])->assertStatus(422);
    }

    public function test_till_delete_is_blocked_when_session_history_exists(): void
    {
        $this->deleteJson("/api/v1/tills/{$this->till->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['till']);

        $this->assertDatabaseHas('tills', ['id' => $this->till->id]);
    }

    public function test_till_can_be_closed_and_reopened_to_free_cashier_assignment(): void
    {
        $code = 'TMP-CLOSE-'.now()->format('His');
        $till = Till::create([
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'till_number' => $code,
            'till_name' => $code,
            'is_active' => true,
            'cashier_id' => $this->user->id,
        ]);

        $this->postJson("/api/v1/tills/{$till->id}/close")
            ->assertOk()
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('cashier_id', null);

        $this->postJson("/api/v1/tills/{$till->id}/reopen")
            ->assertOk()
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('cashier_id', null);
    }

    public function test_till_close_requires_no_active_session(): void
    {
        $session = $this->openFreshSession(1500);

        $this->postJson("/api/v1/tills/{$session->till_id}/close")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['till']);
    }

    public function test_till_delete_succeeds_when_no_session_history(): void
    {
        $code = 'TMP-DELETE-'.now()->format('His');
        $till = Till::create([
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'till_number' => $code,
            'till_name' => $code,
            'is_active' => true,
            'cashier_id' => null,
        ]);

        $this->deleteJson("/api/v1/tills/{$till->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('tills', ['id' => $till->id]);
    }

    public function test_cashier_can_reopen_same_day_session_and_open_fresh_when_no_active_exists(): void
    {
        $session = $this->openFreshSession(2500);

        $this->postJson("/api/v1/pos/sessions/{$session->id}/close", [
            'closing_amount' => 2500,
        ])->assertOk();

        $this->postJson('/api/v1/pos/sessions/open', [
            'till_id' => $this->till->id,
            'branch_id' => $this->user->branch_id,
            'working_amount' => 2500,
            'payment_type' => 'CASH',
        ])
            ->assertOk()
            ->assertJsonPath('id', $session->id)
            ->assertJsonPath('status', 'open');

        $this->postJson("/api/v1/pos/sessions/{$session->id}/close", [
            'closing_amount' => 2500,
        ])->assertOk();

        $reopened = $this->postJson("/api/v1/pos/sessions/{$session->id}/reopen")
            ->assertOk()
            ->json();

        $this->assertSame('open', $reopened['status']);
        $this->assertNull($reopened['closed_at']);
        $this->assertSame($session->id, $reopened['id']);

        $this->postJson("/api/v1/pos/sessions/{$session->id}/close", [
            'closing_amount' => 2500,
        ])->assertOk();

        TillFloatSession::query()->whereKey($session->id)->update([
            'session_date' => now()->subDay()->toDateString(),
        ]);

        $fresh = $this->postJson('/api/v1/pos/sessions/open', [
            'till_id' => $this->till->id,
            'branch_id' => $this->user->branch_id,
            'working_amount' => 2500,
            'payment_type' => 'CASH',
        ])->assertCreated()->json();

        $this->assertNotSame($session->id, $fresh['id']);
        $this->assertSame('open', $fresh['status']);
    }

    public function test_pos_expense_groups_are_scoped_to_user_organization(): void
    {
        $orgId = (int) $this->user->organization_id;
        $otherOrgId = (int) Organization::query()->where('id', '!=', $orgId)->value('id');
        $this->assertGreaterThan(0, $otherOrgId);

        DB::table('expense_groups')->insert([
            ['group_name' => 'Other Org Fuel', 'organization_id' => $otherOrgId],
            ['group_name' => 'Other Org Rent', 'organization_id' => $otherOrgId],
        ]);

        $expectedNames = DB::table('expense_groups')
            ->where('organization_id', $orgId)
            ->orderBy('group_name')
            ->pluck('group_name')
            ->all();

        $response = $this->getJson('/api/v1/pos/expense-groups')->assertOk()->json('data');
        $names = array_column($response, 'group_name');

        $this->assertSame($expectedNames, $names);
        $this->assertNotContains('Other Org Fuel', $names);
        $this->assertNotContains('Other Org Rent', $names);
    }
}
