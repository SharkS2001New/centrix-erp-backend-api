<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrderWorkflowTransitionTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_assign_driver_on_already_processed_order_updates_fulfillment_meta(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $driver = Driver::query()->firstOrFail();

        $sale = Sale::query()->create([
            'order_num' => 992001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'payment_status' => 'paid',
            'order_total' => 500,
            'amount_paid' => 500,
        ]);

        $response = $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'processed',
            'fulfillment_meta' => [
                'driver_id' => $driver->id,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('fulfillment_meta.driver_id', $driver->id);
    }

    public function test_same_status_without_fulfillment_meta_returns_friendly_message(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $sale = Sale::query()->create([
            'order_num' => 992002,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'payment_status' => 'paid',
            'order_total' => 500,
            'amount_paid' => 500,
        ]);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'processed',
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Order is already marked as processed.']);
    }

    public function test_credit_sale_can_advance_to_processed_while_payment_remains_unpaid(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $sale = Sale::query()->create([
            'order_num' => 992003,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'is_credit_sale' => true,
            'order_total' => 1200,
            'amount_paid' => 0,
        ]);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'processed',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('payment_status', 'unpaid');

        $sale->refresh();
        $this->assertSame('processed', $sale->status);
        $this->assertSame('unpaid', $sale->payment_status);
    }

    public function test_unpaid_order_can_advance_to_processed_without_credit_flag(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $sale = Sale::query()->create([
            'order_num' => 992004,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'is_credit_sale' => false,
            'order_total' => 900,
            'amount_paid' => 0,
        ]);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'processed',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('payment_status', 'unpaid');
    }

    public function test_processed_transition_lists_products_missing_weight_when_required(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $org = Organization::findOrFail($admin->organization_id);
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $settings['distribution'] = array_merge($settings['distribution'] ?? [], [
            'require_weight_on_load' => true,
        ]);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);

        $product = Product::query()->firstOrFail();
        $product->update(['product_weight' => 0]);

        $sale = Sale::query()->create([
            'order_num' => 992005,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 900,
            'amount_paid' => 0,
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'amount' => 900,
            'on_wholesale_retail' => 0,
        ]);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'processed',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'missing_product_weights')
            ->assertJsonPath('products.0.product_code', $product->product_code)
            ->assertJsonFragment([
                'message' => 'Set product weight (kg per unit) so order tonnage can be calculated: '
                    ."{$product->product_code} ({$product->product_name}).",
            ]);
    }

    public function test_order_product_weights_endpoint_updates_weights_and_returns_status(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = Product::query()->firstOrFail();
        $product->update(['product_weight' => 0]);

        $sale = Sale::query()->create([
            'order_num' => 992007,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 900,
            'amount_paid' => 0,
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'amount' => 900,
            'on_wholesale_retail' => 0,
        ]);

        $this->getJson("/api/v1/sales/orders/{$sale->id}/load-weight-status")
            ->assertOk()
            ->assertJsonPath('ready', false)
            ->assertJsonPath('missing_products.0.product_code', $product->product_code);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/product-weights", [
            'weights' => [
                ['product_code' => $product->product_code, 'product_weight' => 1.25],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('total_weight_kg', 2.5);
    }

    public function test_processed_transition_succeeds_when_products_have_weight_and_required(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $org = Organization::findOrFail($admin->organization_id);
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $settings['distribution'] = array_merge($settings['distribution'] ?? [], [
            'require_weight_on_load' => true,
        ]);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);

        $product = Product::query()->firstOrFail();
        $product->update(['product_weight' => 1.5]);

        $sale = Sale::query()->create([
            'order_num' => 992006,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'order_total' => 900,
            'amount_paid' => 0,
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'amount' => 900,
            'on_wholesale_retail' => 0,
        ]);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'processed',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'processed');
    }

    public function test_backoffice_cannot_mark_processed_order_delivered_without_fulfillment_context(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $org = Organization::findOrFail($admin->organization_id);
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);

        $sale = Sale::query()->create([
            'order_num' => 992007,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'order_total' => 900,
            'amount_paid' => 0,
        ]);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'delivered',
        ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Mark this order as delivered from the Distribution module after the trip is dispatched.',
            ]);
    }

    public function test_backoffice_cannot_manually_complete_distribution_order(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $org = Organization::findOrFail($admin->organization_id);
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);

        $sale = Sale::query()->create([
            'order_num' => 992008,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'order_total' => 900,
            'amount_paid' => 900,
        ]);

        $this->postJson("/api/v1/sales/orders/{$sale->id}/transition", [
            'status' => 'completed',
        ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Complete this order by collecting payment at the till.',
            ]);
    }
}
