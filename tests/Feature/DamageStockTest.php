<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class DamageStockTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected string $productCode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->productCode = Product::first()->product_code;
        Sanctum::actingAs($this->user);
    }

    public function test_recording_damage_deducts_branch_stock(): void
    {
        $before = $this->onHandShop();

        $damage = $this->postJson('/api/v1/damages', [
            'product_code' => $this->productCode,
            'branch_id' => $this->user->branch_id,
            'quantity' => 4,
            'package_type' => 'partial',
            'stock_location' => 'shop',
            'reason' => 'Broken packaging',
        ])->assertCreated()->json();

        $this->assertDatabaseHas('inventory_transactions', [
            'product_code' => $this->productCode,
            'transaction_type' => 'DAMAGE',
            'reference_type' => 'damage',
            'reference_id' => $damage['id'],
            'quantity_change' => -4,
        ]);

        $this->assertEquals($before - 4, $this->onHandShop());
    }

    public function test_updating_damage_adjusts_ledger_and_stock(): void
    {
        $before = $this->onHandShop();

        $damage = $this->postJson('/api/v1/damages', [
            'product_code' => $this->productCode,
            'branch_id' => $this->user->branch_id,
            'quantity' => 2,
            'package_type' => 'partial',
            'stock_location' => 'shop',
            'reason' => 'Initial',
        ])->assertCreated()->json();

        $this->assertEquals($before - 2, $this->onHandShop());

        $this->putJson("/api/v1/damages/{$damage['id']}", [
            'quantity' => 5,
            'stock_location' => 'shop',
            'reason' => 'Recounted',
        ])->assertOk();

        $this->assertDatabaseHas('inventory_transactions', [
            'reference_type' => 'damage_reversal',
            'reference_id' => $damage['id'],
            'quantity_change' => 2,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'reference_type' => 'damage',
            'reference_id' => $damage['id'],
            'quantity_change' => -5,
        ]);

        $this->assertEquals($before - 5, $this->onHandShop());
    }

    public function test_deleting_damage_restores_stock(): void
    {
        $before = $this->onHandShop();

        $damage = $this->postJson('/api/v1/damages', [
            'product_code' => $this->productCode,
            'branch_id' => $this->user->branch_id,
            'quantity' => 3,
            'package_type' => 'partial',
            'stock_location' => 'shop',
            'reason' => 'Spillage',
        ])->assertCreated()->json();

        $this->assertEquals($before - 3, $this->onHandShop());

        $this->deleteJson("/api/v1/damages/{$damage['id']}")->assertNoContent();

        $this->assertDatabaseHas('inventory_transactions', [
            'reference_type' => 'damage_reversal',
            'reference_id' => $damage['id'],
            'quantity_change' => 3,
        ]);
        $this->assertEquals($before, $this->onHandShop());
    }

    public function test_products_include_branch_stock_when_branch_id_provided(): void
    {
        $row = CurrentStock::where('product_code', $this->productCode)
            ->where('branch_id', $this->user->branch_id)
            ->firstOrFail();

        $product = $this->getJson('/api/v1/products/'.$this->productCode.'?branch_id='.$this->user->branch_id)
            ->assertOk()
            ->json();

        $this->assertEquals((float) $row->shop_quantity, (float) $product['stock_in_shop']);
        $this->assertEquals((float) $row->store_quantity, (float) $product['stock_in_store']);
        $this->assertEquals((int) $this->user->branch_id, (int) $product['branch_stock']['branch_id']);
    }

    public function test_current_stock_cannot_be_written_directly(): void
    {
        $this->postJson('/api/v1/current-stock', [
            'product_code' => $this->productCode,
            'branch_id' => $this->user->branch_id,
            'shop_quantity' => 999,
        ])->assertNotFound();
    }

    public function test_recording_damage_rejects_product_not_visible_at_branch(): void
    {
        $otherBranch = \App\Models\Branch::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('id', '!=', $this->user->branch_id)
            ->first();

        if (! $otherBranch) {
            $this->markTestSkipped('Needs a second branch in the seeded organization.');
        }

        $product = Product::query()->where('product_code', $this->productCode)->firstOrFail();
        $product->update(['branch_id' => $otherBranch->id]);

        $this->postJson('/api/v1/damages', [
            'product_code' => $this->productCode,
            'branch_id' => $this->user->branch_id,
            'quantity' => 1,
            'package_type' => 'partial',
            'stock_location' => 'shop',
            'reason' => 'Wrong branch product',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['product_code']);
    }

    protected function onHandShop(): float
    {
        return (float) CurrentStock::where('product_code', $this->productCode)
            ->where('branch_id', $this->user->branch_id)
            ->value('shop_quantity');
    }
}
