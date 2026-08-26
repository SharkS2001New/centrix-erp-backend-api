<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use App\Services\Ai\Tools\GetSalesByCashierTool;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiSalesByCashierToolTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('username', 'admin')->firstOrFail();
    }

    public function test_sales_by_cashier_uses_placed_date_and_cashier_name_filter(): void
    {
        $otherCashier = User::where('username', 'cashier')->firstOrFail();
        $placedDay = now()->subDay()->toDateString();
        $completedDay = now()->toDateString();

        $sale = Sale::query()->create([
            'order_num' => 995099,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $this->admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 6870,
            'total_vat' => 687,
            'amount_paid' => 6870,
            'archived' => 0,
            'completed_at' => $completedDay.' 18:00:00',
        ]);
        $sale->forceFill(['created_at' => $placedDay.' 09:15:00'])->save();

        $noise = Sale::query()->create([
            'order_num' => 995100,
            'branch_id' => $otherCashier->branch_id,
            'organization_id' => $otherCashier->organization_id,
            'channel' => 'pos',
            'cashier_id' => $otherCashier->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 1200,
            'total_vat' => 120,
            'amount_paid' => 1200,
            'archived' => 0,
            'completed_at' => $placedDay.' 11:00:00',
        ]);
        $noise->forceFill(['created_at' => $placedDay.' 10:00:00'])->save();

        Sanctum::actingAs($this->admin);

        /** @var GetSalesByCashierTool $tool */
        $tool = app(GetSalesByCashierTool::class);
        $result = $tool->execute($this->admin, [
            'relative_date' => 'yesterday',
            'cashier_name' => $this->admin->full_name ?: $this->admin->username,
        ]);

        $this->assertSame($placedDay, $result['from_date']);
        $this->assertSame($placedDay, $result['to_date']);
        $this->assertCount(1, $result['cashiers']);
        $this->assertSame($this->admin->username, $result['cashiers'][0]['username'] ?? null);
        $this->assertArrayNotHasKey('cashier_id', $result['cashiers'][0]);
        $this->assertEqualsWithDelta(6870.0, (float) $result['cashiers'][0]['gross_sales'], 0.01);
        $this->assertEqualsWithDelta(6870.0, (float) $result['cashiers'][0]['amount_collected'], 0.01);
        $this->assertEqualsWithDelta(6870.0, (float) $result['cashiers'][0]['fully_paid_sales'], 0.01);
        $this->assertSame(1, (int) $result['cashiers'][0]['transactions']);
        $this->assertSame($this->admin->username, $result['matched_username'] ?? null);
    }

    public function test_sales_by_cashier_separates_credit_gross_from_fully_paid(): void
    {
        $placedDay = now()->subDay()->toDateString();
        $paid = Sale::query()->create([
            'order_num' => 995201,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $this->admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 1000,
            'total_vat' => 100,
            'amount_paid' => 1000,
            'is_credit_sale' => 0,
            'archived' => 0,
            'completed_at' => $placedDay.' 10:00:00',
        ]);
        $paid->forceFill(['created_at' => $placedDay.' 10:00:00'])->save();

        $credit = Sale::query()->create([
            'order_num' => 995202,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $this->admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 4000,
            'total_vat' => 400,
            'amount_paid' => 0,
            'is_credit_sale' => 1,
            'archived' => 0,
            'completed_at' => $placedDay.' 11:00:00',
        ]);
        $credit->forceFill(['created_at' => $placedDay.' 11:00:00'])->save();

        Sanctum::actingAs($this->admin);
        $result = app(GetSalesByCashierTool::class)->execute($this->admin, [
            'relative_date' => 'yesterday',
            'username' => $this->admin->username,
        ]);

        $this->assertCount(1, $result['cashiers']);
        $this->assertEqualsWithDelta(5000.0, (float) $result['cashiers'][0]['gross_sales'], 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $result['cashiers'][0]['amount_collected'], 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $result['cashiers'][0]['fully_paid_sales'], 0.01);
        $this->assertSame(2, (int) $result['cashiers'][0]['transactions']);
        $this->assertSame(1, (int) $result['cashiers'][0]['fully_paid_transactions']);
    }

    public function test_sales_by_cashier_prefers_exact_username_over_partial_name(): void
    {
        $suffix = substr(uniqid(), -6);
        $target = User::query()->create([
            'organization_id' => $this->admin->organization_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->role_id,
            'username' => 'chege',
            'full_name' => 'Chege Maina',
            'email' => "chege_{$suffix}@example.test",
            'password' => bcrypt('secret'),
            'is_admin' => false,
            'access_scope' => 'org',
            'is_active' => true,
        ]);
        User::query()->create([
            'organization_id' => $this->admin->organization_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->role_id,
            'username' => 'chege_assistant',
            'full_name' => 'Chege Assistant',
            'email' => "chege_asst_{$suffix}@example.test",
            'password' => bcrypt('secret'),
            'is_admin' => false,
            'access_scope' => 'org',
            'is_active' => true,
        ]);

        $day = now()->subDay()->toDateString();
        $sale = Sale::query()->create([
            'order_num' => 995101,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $target->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 2500,
            'total_vat' => 250,
            'amount_paid' => 2500,
            'archived' => 0,
            'completed_at' => $day.' 12:00:00',
        ]);
        $sale->forceFill(['created_at' => $day.' 09:00:00'])->save();

        Sanctum::actingAs($this->admin);
        $result = app(GetSalesByCashierTool::class)->execute($this->admin, [
            'relative_date' => 'yesterday',
            'cashier_name' => 'CHEGE',
        ]);

        $this->assertSame(
            mb_strtolower((string) $target->username),
            mb_strtolower((string) ($result['matched_username'] ?? '')),
        );
        $this->assertCount(1, $result['cashiers']);
        $this->assertEqualsWithDelta(2500.0, (float) $result['cashiers'][0]['gross_sales'], 0.01);
        $this->assertStringNotContainsStringIgnoringCase('assistant', (string) ($result['matched_username'] ?? ''));
    }
}
