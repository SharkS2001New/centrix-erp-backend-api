<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SaleMergeTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_admin_can_merge_two_mobile_orders_for_same_customer(): void
    {
        $customer = $this->createCustomer('Merge Customer');
        $routeId = $this->firstRouteId();
        $first = $this->createMobileSale($customer->customer_num, $routeId, 2, 200.0);
        $second = $this->createMobileSale($customer->customer_num, $routeId, 3, 300.0);

        $response = $this->postJson('/api/v1/sales/orders/merge', [
            'sale_ids' => [$first->id, $second->id],
            'target_sale_id' => $first->id,
        ])->assertOk();

        $this->assertSame($first->id, (int) $response->json('id'));
        $this->assertEquals(500.0, (float) $response->json('order_total'));

        $this->assertSame(1, SaleItem::query()->where('sale_id', $first->id)->count());
        $item = SaleItem::query()->where('sale_id', $first->id)->first();
        $this->assertEquals(5.0, (float) $item->quantity);
        $this->assertEquals(500.0, (float) $item->amount);

        $second->refresh();
        $this->assertSame('cancelled', $second->status);
        $this->assertSame($first->id, (int) ($second->fulfillment_meta['merged_into_sale_id'] ?? 0));
        $this->assertSame(0, SaleItem::query()->where('sale_id', $second->id)->count());
    }

    public function test_merge_rejects_different_customers(): void
    {
        $routeId = $this->firstRouteId();
        $a = $this->createCustomer('Customer A');
        $b = $this->createCustomer('Customer B');
        $first = $this->createMobileSale($a->customer_num, $routeId, 1, 100.0);
        $second = $this->createMobileSale($b->customer_num, $routeId, 1, 100.0);

        $this->postJson('/api/v1/sales/orders/merge', [
            'sale_ids' => [$first->id, $second->id],
        ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Orders can only be merged when they belong to the same registered customer.',
            ]);
    }

    public function test_merge_rejects_backoffice_orders(): void
    {
        $customer = $this->createCustomer('Backoffice Customer');
        $routeId = $this->firstRouteId();
        $first = $this->createMobileSale($customer->customer_num, $routeId, 1, 100.0);
        $second = $this->createMobileSale($customer->customer_num, $routeId, 1, 100.0);
        $second->update(['channel' => 'backend', 'order_source' => 'backoffice']);

        $this->postJson('/api/v1/sales/orders/merge', [
            'sale_ids' => [$first->id, $second->id],
        ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Only mobile orders can be merged.',
            ]);
    }

    protected function firstRouteId(): ?int
    {
        $id = \App\Models\RouteModel::query()->value('id');

        return $id ? (int) $id : null;
    }

    protected function createCustomer(string $name): Customer
    {
        $max = (int) Customer::query()->max('customer_num');

        return Customer::create([
            'customer_num' => $max + 1,
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'customer_name' => $name,
            'customer_type' => 'regular',
            'phone_number' => '07'.random_int(10000000, 99999999),
            'created_by' => $this->user->id,
        ]);
    }

    protected function createMobileSale(int $customerNum, ?int $routeId, float $qty, float $amount, string $status = 'booked'): Sale
    {
        $product = Product::query()->firstOrFail();
        $unitPrice = round($amount / $qty, 4);
        $product->forceFill(['unit_price' => $unitPrice])->save();
        RetailPackageSetting::query()
            ->where('product_code', $product->product_code)
            ->delete();

        $sale = Sale::create([
            'order_num' => (int) (Sale::query()->max('order_num') ?? 0) + 1,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $this->user->organization_id,
            'channel' => 'mobile',
            'order_source' => 'mobile',
            'cashier_id' => $this->user->id,
            'customer_num' => $customerNum,
            'route_id' => $routeId,
            'status' => $status,
            'total_vat' => 0,
            'order_total' => $amount,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'payment_method_code' => 'CASH',
            'is_credit_sale' => 0,
            'stock_balanced' => 0,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => $qty,
            'uom' => $product->uom,
            'selling_price' => $unitPrice,
            'discount_given' => 0,
            'product_vat' => 0,
            'amount' => $amount,
            'on_wholesale_retail' => 0,
        ]);

        return $sale->fresh('items');
    }
}
