<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\Tools\GetOrderDetailsTool;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiGetOrderDetailsToolTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('username', 'admin')->firstOrFail();
    }

    public function test_registry_includes_get_order_details(): void
    {
        $names = array_map(fn ($tool) => $tool->name(), app(AiToolRegistry::class)->all());
        $this->assertContains('get_order_details', $names);
    }

    public function test_looks_up_order_by_s_prefix_and_returns_payments(): void
    {
        $sale = Sale::query()->create([
            'order_num' => 1537,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $this->admin->id,
            'customer_name_override' => 'Kilimani Distributors',
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 12500,
            'total_vat' => 1250,
            'amount_paid' => 12500,
            'archived' => 0,
            'completed_at' => now(),
        ]);

        $method = PaymentMethod::query()->firstOrCreate(
            [
                'organization_id' => $this->admin->organization_id,
                'method_code' => 'MPESA',
            ],
            [
                'method_name' => 'M-Pesa',
                'requires_reference' => true,
                'is_active' => true,
            ],
        );

        SalePayment::query()->create([
            'sale_id' => $sale->id,
            'payment_method_id' => $method->id,
            'amount' => 12500,
            'reference_number' => 'QWER123',
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);

        /** @var GetOrderDetailsTool $tool */
        $tool = app(GetOrderDetailsTool::class);

        foreach (['S1537', '1537', 'invoice 1537', 'AR-1537'] as $ref) {
            $result = $tool->execute($this->admin, ['order_num' => $ref]);
            $this->assertArrayNotHasKey('error', $result, "Failed for ref {$ref}");
            $this->assertSame(1537, (int) ($result['order_num'] ?? 0));
            $this->assertSame('S1537', $result['order_num_display'] ?? null);
            $this->assertSame('Kilimani Distributors', $result['customer_name'] ?? null);
            $this->assertSame('paid', $result['payment_status'] ?? null);
            $this->assertEqualsWithDelta(12500.0, (float) ($result['order_total'] ?? 0), 0.01);
            $this->assertEqualsWithDelta(0.0, (float) ($result['balance_due'] ?? -1), 0.01);
            $this->assertSame('/sales/orders/'.$sale->id, $result['path'] ?? null);
            $this->assertNotEmpty($result['payments'] ?? []);
            $this->assertSame('QWER123', $result['payments'][0]['reference_number'] ?? null);
            $this->assertNotEmpty($result['correction_guidance'] ?? []);
        }
    }

    public function test_missing_order_returns_helpful_error(): void
    {
        Sanctum::actingAs($this->admin);
        $result = app(GetOrderDetailsTool::class)->execute($this->admin, [
            'order_num' => 'S999999',
        ]);

        $this->assertTrue((bool) ($result['error'] ?? false));
        $this->assertStringContainsString('S999999', (string) ($result['message'] ?? ''));
        $this->assertNotEmpty($result['screens'] ?? []);
    }
}
