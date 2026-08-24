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
        $this->assertSame(1, (int) $result['cashiers'][0]['transactions']);
    }
}
