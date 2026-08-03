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
}
