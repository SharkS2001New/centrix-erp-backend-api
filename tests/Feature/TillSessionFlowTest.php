<?php

namespace Tests\Feature;

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

    protected function openFreshSession(float $float = 5000): TillFloatSession
    {
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
