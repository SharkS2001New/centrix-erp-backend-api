<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockReservation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_cancelled_unpaid_order_is_excluded_from_unpaid_queue(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $openUnpaid = Sale::query()->create([
            'order_num' => 994001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 500,
            'amount_paid' => 0,
        ]);

        $cancelledUnpaid = Sale::query()->create([
            'order_num' => 994002,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'cancelled',
            'payment_status' => 'unpaid',
            'order_total' => 800,
            'amount_paid' => 0,
            'cancelled_at' => now(),
            'cancelled_by' => $admin->id,
        ]);

        $response = $this->getJson('/api/v1/sales?filter[payment_status]=unpaid&per_page=200');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($openUnpaid->id));
        $this->assertFalse($ids->contains($cancelledUnpaid->id));
    }

    public function test_cancel_unpaid_order_voids_customer_invoice_and_releases_reservations(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $customer = Customer::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        $cart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'backend',
            'branch_id' => $admin->branch_id,
        ])->assertCreated()->json();

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 3,
            'unit_price' => 100,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
            'customer_num' => $customer->customer_num,
            'save_only' => true,
        ])->assertCreated()->json();

        $saleId = (int) $sale['id'];
        $this->assertEquals('unpaid', $sale['status'] ?? null);

        $this->assertDatabaseHas('customer_invoices', [
            'sale_id' => $saleId,
            'deleted_at' => null,
        ]);

        $this->assertTrue(
            StockReservation::query()
                ->where('sale_id', $saleId)
                ->whereNull('released_at')
                ->exists()
        );

        $this->postJson("/api/v1/sales/orders/{$saleId}/transition", [
            'status' => 'cancelled',
        ])->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('customer_invoices', [
            'sale_id' => $saleId,
        ]);
        $this->assertNotNull(CustomerInvoice::query()->where('sale_id', $saleId)->value('deleted_at'));

        $this->assertFalse(
            StockReservation::query()
                ->where('sale_id', $saleId)
                ->whereNull('released_at')
                ->exists()
        );

        $customer->refresh();
        $this->assertEquals(0.0, (float) $customer->current_balance);
    }

    public function test_completed_order_cannot_be_cancelled_via_workflow(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $sale = Sale::query()->create([
            'order_num' => 994003,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 1000,
            'amount_paid' => 1000,
        ]);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'cancelled',
        ])->assertStatus(422);

        $this->assertEquals('completed', $sale->fresh()->status);
    }
}
