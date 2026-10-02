<?php

namespace Tests\Feature;

use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
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

    public function test_order_details_include_pending_return_blocking_max_returnable(): void
    {
        $product = Product::query()
            ->where('organization_id', $this->admin->organization_id)
            ->whereNotNull('unit_id')
            ->first() ?? Product::query()->where('organization_id', $this->admin->organization_id)->firstOrFail();

        $sale = Sale::query()->create([
            'order_num' => 1446,
            'branch_id' => $this->admin->branch_id,
            'organization_id' => $this->admin->organization_id,
            'channel' => 'pos',
            'cashier_id' => $this->admin->id,
            'customer_name_override' => 'Serviette Customer',
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 3000,
            'total_vat' => 0,
            'amount_paid' => 3000,
            'archived' => 0,
            'completed_at' => now(),
        ]);

        $item = SaleItem::query()->create([
            'sale_id' => $sale->id,
            'line_no' => 1,
            'product_code' => $product->product_code,
            'quantity' => 3,
            'selling_price' => 1000,
            'display_unit_price' => 1000,
            'amount' => 3000,
            'product_vat' => 0,
            'discount_given' => 0,
            'on_wholesale_retail' => 0,
            'uom' => 'CARTON',
        ]);

        $return = CustomerReturn::query()->create([
            'organization_id' => $this->admin->organization_id,
            'branch_id' => $this->admin->branch_id,
            'sale_id' => $sale->id,
            'return_no' => 'RET-TEST-1446',
            'return_seq' => 1446001,
            'return_date' => now()->toDateString(),
            'status' => 'pending',
            'total_amount' => 2000,
            'reason' => 'Damaged Product',
            'refund_method' => 'CASH',
            'returned_by' => $this->admin->id,
            'return_kind' => 'standard',
        ]);

        CustomerReturnLine::query()->create([
            'customer_return_id' => $return->id,
            'sale_item_id' => $item->id,
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'return_qty' => 2,
            'quantity_sold' => 3,
            'unit_price' => 1000,
            'amount' => 2000,
        ]);

        Sanctum::actingAs($this->admin);
        $result = app(GetOrderDetailsTool::class)->execute($this->admin, [
            'order_num' => 'S1446',
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayHasKey('returns', $result);
        $this->assertTrue((bool) ($result['returns']['has_pending_returns'] ?? false));
        $this->assertNotEmpty($result['returns']['pending_returns'] ?? []);
        $this->assertSame('RET-TEST-1446', $result['returns']['pending_returns'][0]['return_no'] ?? null);

        $line = collect($result['returns']['returnable_lines'] ?? [])
            ->firstWhere('product_code', $product->product_code);
        $this->assertNotNull($line);
        $this->assertGreaterThan(0.0, (float) ($line['pending_return_qty'] ?? 0));
        $this->assertLessThan(
            (float) ($line['qty_sold'] ?? 0),
            (float) ($line['max_return_qty'] ?? 0) + 0.0001,
        );
        $this->assertStringContainsString('pending', strtolower((string) ($result['direct_answer'] ?? '')));
    }
}
