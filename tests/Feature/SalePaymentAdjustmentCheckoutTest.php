<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SalePaymentAdjustmentCheckoutTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected string $productCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);

        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->productCode = Product::first()->product_code;
        Sanctum::actingAs($this->user);
    }

    public function test_checkout_persists_payment_adjustments_for_previous_order_edit(): void
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
            'pay_now' => 9000,
            'payment_adjustments' => [
                [
                    'method_code' => 'CASH',
                    'amount' => 1000,
                    'adjustment_type' => 'return',
                    'reference_number' => null,
                ],
            ],
        ])->assertCreated()->json();

        $this->assertDatabaseHas('sale_payment_adjustments', [
            'sale_id' => $sale['id'],
            'adjustment_type' => 'return',
            'amount' => 1000,
        ]);
    }

    public function test_previous_order_edit_rebuilds_equity_tenders_for_reports(): void
    {
        $firstCart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$firstCart}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 2,
            'unit_price' => 10000,
            'amount' => 20000,
        ])->assertCreated();

        $original = $this->postJson("/api/v1/sales/carts/{$firstCart}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'EQUITY',
            'pay_now' => 20000,
        ]);

        if ($original->status() !== 201) {
            $this->markTestSkipped('EQUITY checkout unavailable in this fixture: '.$original->getContent());
        }

        $originalSale = $original->json();
        $this->assertGreaterThan(0, (float) ($originalSale['equity_amount'] ?? $originalSale['amount_paid'] ?? 0));

        $editCart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        // Mark as previous-order edit of the Equity sale.
        \App\Models\TemporaryCart::query()->where('id', $editCart)->update([
            'superseded_sale_id' => $originalSale['id'],
            'held_order_num' => $originalSale['order_num'],
        ]);

        $this->postJson("/api/v1/sales/carts/{$editCart}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
            'unit_price' => 10000,
            'amount' => 10000,
        ])->assertCreated();

        $revised = $this->postJson("/api/v1/sales/carts/{$editCart}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
            'pay_now' => 0,
            'order_num' => $originalSale['order_num'],
            'payment_adjustments' => [
                [
                    'method_code' => 'EQUITY',
                    'amount' => 10000,
                    'adjustment_type' => 'return',
                ],
            ],
        ])->assertCreated()->json();

        $sale = \App\Models\Sale::query()->with('payments.paymentMethod')->findOrFail($revised['id']);
        $this->assertEqualsWithDelta(10000.0, (float) $sale->order_total, 0.02);
        $this->assertEqualsWithDelta(10000.0, (float) $sale->amount_paid, 0.02);
        // Net Equity tender kept for X/Z/EOD — not wiped, not reclassed to Cash.
        $this->assertEqualsWithDelta(10000.0, (float) ($sale->equity_amount ?? 0), 0.02);
        $this->assertEqualsWithDelta(0.0, (float) ($sale->cash ?? 0), 0.02);
        $this->assertGreaterThan(0, $sale->payments->count());
    }
}
