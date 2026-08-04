<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TemporaryCart;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileOrderEditKeepsUnpaidTest extends TestCase
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

    public function test_previous_order_edit_of_unpaid_mobile_sale_stays_unpaid(): void
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'backend',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $original = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'save_only' => true,
            'pay_now' => 0,
        ])->assertCreated()->json();

        $this->assertSame('unpaid', $original['payment_status'] ?? null);
        $this->assertEqualsWithDelta(0.0, (float) ($original['amount_paid'] ?? 0), 0.01);

        $editCart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'backend',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        TemporaryCart::query()->where('id', $editCart)->update([
            'superseded_sale_id' => $original['id'],
            'held_order_num' => $original['order_num'] ?? null,
        ]);

        $this->postJson("/api/v1/sales/carts/{$editCart}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $edited = $this->postJson("/api/v1/sales/carts/{$editCart}/checkout", [
            'save_only' => true,
            'pay_now' => 0,
        ])->assertCreated()->json();

        $this->assertSame('unpaid', $edited['payment_status'] ?? null, 'Edited unpaid order must stay unpaid');
        $this->assertEqualsWithDelta(0.0, (float) ($edited['amount_paid'] ?? 0), 0.01);

        $sale = Sale::query()->findOrFail($edited['id']);
        $this->assertSame(0, $sale->payments()->count());
        $this->assertEqualsWithDelta(0.0, (float) ($sale->cash ?? 0), 0.01);
    }
}
