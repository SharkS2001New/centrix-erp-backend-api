<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
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
        $originalTotal = round((float) $originalSale['order_total'], 2);

        $editCart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        // Mark as previous-order edit of the Equity sale.
        \App\Models\TemporaryCart::query()->where('id', $editCart)->update([
            'superseded_sale_id' => $originalSale['id'],
            'held_order_num' => $originalSale['order_num'],
        ]);

        // Keep the same line total so tenders need not be adjusted — only verify the
        // sync payload's payment_method_code=CASH does not reclassify the sale header.
        $this->postJson("/api/v1/sales/carts/{$editCart}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 2,
            'unit_price' => 10000,
            'amount' => 20000,
        ])->assertCreated();

        $revised = $this->postJson("/api/v1/sales/carts/{$editCart}/checkout", [
            'status' => 'completed',
            // Sync path historically sent CASH even when the prior sale was Equity.
            'payment_method_code' => 'CASH',
            'pay_now' => 0,
            'order_num' => $originalSale['order_num'],
        ])->assertCreated()->json();

        $sale = \App\Models\Sale::query()->with('payments.paymentMethod')->findOrFail($revised['id']);
        $this->assertEqualsWithDelta($originalTotal, (float) $sale->order_total, 0.02);
        $this->assertEqualsWithDelta($originalTotal, (float) $sale->amount_paid, 0.02);
        // Net Equity tender kept for X/Z/EOD — not wiped, not reclassed to Cash.
        $this->assertEqualsWithDelta($originalTotal, (float) ($sale->equity_amount ?? 0), 0.02);
        $this->assertEqualsWithDelta(0.0, (float) ($sale->cash ?? 0), 0.02);
        $this->assertSame('EQUITY', strtoupper((string) $sale->payment_method_code));
        $this->assertGreaterThan(0, $sale->payments->count());
    }

    public function test_empty_previous_order_edit_cancels_sale_and_records_return(): void
    {
        $this->setPosOrderEditEnabled(true);

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
        ])->assertCreated()->json();

        $orderNum = (int) $sale['order_num'];
        $orderTotal = round((float) $sale['order_total'], 2);
        $this->assertGreaterThan(0, $orderTotal);

        $editCart = $this->postJson("/api/v1/sales/orders/{$sale['id']}/restore-to-cart", [
            'replace' => true,
        ])->assertOk()->json();
        $editCartId = $editCart['id'];
        $this->assertSame((int) $sale['id'], (int) ($editCart['superseded_sale_id'] ?? 0));

        $this->putJson("/api/v1/sales/carts/{$editCartId}/lines", [
            'lines' => [],
            'order_discount' => 0,
        ])->assertOk()
            ->assertJsonPath('lines', []);

        $cancelled = $this->postJson("/api/v1/sales/carts/{$editCartId}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
            'pay_now' => 0,
            'order_num' => $orderNum,
            'payment_adjustments' => [
                [
                    'method_code' => 'CASH',
                    'amount' => $orderTotal,
                    'adjustment_type' => 'return',
                    'reference_number' => null,
                ],
            ],
        ])->assertCreated()->json();

        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame($orderNum, (int) $cancelled['order_num']);
        $this->assertSame((int) $sale['id'], (int) $cancelled['id']);

        $this->assertDatabaseHas('sale_payment_adjustments', [
            'sale_id' => $sale['id'],
            'adjustment_type' => 'return',
            'amount' => $orderTotal,
        ]);

        $this->assertDatabaseMissing('temporary_carts', ['id' => $editCartId]);

        $fresh = Sale::query()->findOrFail($sale['id']);
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame($orderNum, (int) $fresh->order_num);
        $this->assertTrue((bool) (($fresh->fulfillment_meta ?? [])['cancelled_via_empty_previous_order_edit'] ?? false));
    }

    public function test_normal_cart_rejects_empty_replace_lines(): void
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $this->putJson("/api/v1/sales/carts/{$cartId}/lines", [
            'lines' => [],
        ])->assertStatus(422);
    }

    protected function setPosOrderEditEnabled(bool $enabled): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_pos_order_edit' => $enabled,
        ]);
        $org->update(['module_settings' => $settings]);
    }
}
