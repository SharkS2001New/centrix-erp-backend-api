<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Ai\Tools\GetCustomerStatementTool;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiCustomerStatementToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_returns_balance_and_what_customer_bought(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $customer = Customer::query()
            ->where('organization_id', $org->id)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $product = Product::query()
            ->where('organization_id', $org->id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $customer->current_balance = 2500;
        $customer->save();

        $sale = Sale::query()->create([
            'order_num' => 99001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $org->id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'completed',
            'completed_at' => '2026-08-12 10:00:00',
            'total_vat' => 0,
            'order_total' => 1500,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'is_credit_sale' => true,
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'line_no' => 1,
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'quantity' => 3,
            'selling_price' => 500,
            'discount_given' => 0,
            'amount' => 1500,
        ]);

        $expectedBalance = (float) $customer->fresh()->current_balance;

        Sanctum::actingAs($admin);

        /** @var GetCustomerStatementTool $tool */
        $tool = app(GetCustomerStatementTool::class);
        $result = $tool->execute($admin, [
            'customer_num' => (string) $customer->customer_num,
            'month' => 'august',
            'year' => 2026,
        ]);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('2026-08-01', $result['period']['from_date'] ?? null);
        $this->assertSame('2026-08-31', $result['period']['to_date'] ?? null);
        $this->assertSame($expectedBalance, (float) ($result['summary']['current_balance_due'] ?? 0));
        $this->assertGreaterThanOrEqual(1500.0, (float) ($result['summary']['period_purchases_total'] ?? 0));
        $codes = collect($result['purchases_by_product'] ?? [])->pluck('product_code')->all();
        $this->assertContains($product->product_code, $codes);
        $matched = collect($result['purchases_by_product'] ?? [])
            ->firstWhere('product_code', $product->product_code);
        $this->assertNotNull($matched);
        $this->assertArrayHasKey('qty_label', $matched);
        $this->assertTrue(
            collect($result['line_items'] ?? [])->contains(
                fn ($line) => ($line['product_code'] ?? null) === $product->product_code
                    && (int) ($line['sale_id'] ?? 0) === (int) $sale->id,
            ),
        );
    }
}
