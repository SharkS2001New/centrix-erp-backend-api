<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\ModuleRegistry;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BackofficeSalesCartTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_backoffice_cart_uses_backend_channel_when_external_pos_disabled(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->enabled_modules = ModuleRegistry::cascade([
            'sales' => true,
            'sales.pos' => false,
            'sales.backend' => true,
            'inventory' => true,
            'customers_suppliers' => true,
        ]);
        $org->save();

        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'order_source' => 'backoffice',
            'branch_id' => $user->branch_id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('channel', 'backend')
            ->assertJsonPath('order_source', 'backoffice');
    }

    public function test_pos_line_add_skips_order_num_peek_but_create_and_show_keep_it(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->enabled_modules = ModuleRegistry::cascade([
            'sales' => true,
            'sales.pos' => true,
            'sales.backend' => true,
            'inventory' => true,
            'customers_suppliers' => true,
        ]);
        $org->save();

        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);
        $product = Product::firstOrFail();

        $cart = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'order_source' => 'pos',
            'branch_id' => $user->branch_id,
        ])->assertCreated()->json();

        $this->assertArrayHasKey('next_order_num', $cart);

        $added = $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 100,
            'on_wholesale_retail' => 0,
        ])->assertCreated()->json();

        $this->assertArrayHasKey('lines', $added);
        $this->assertArrayNotHasKey('next_order_num', $added);
        $this->assertArrayNotHasKey('next_pos_order_num', $added);

        $shown = $this->getJson("/api/v1/sales/carts/{$cart['id']}")
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('next_order_num', $shown);
    }
}
