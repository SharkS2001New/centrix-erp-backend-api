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

    public function test_office_previous_order_edit_keeps_mobile_rep_cashier_on_revised_sale(): void
    {
        $admin = $this->user;
        $routeId = (int) (\App\Models\RouteModel::query()->value('id') ?? 0);
        $this->assertGreaterThan(0, $routeId, 'Seed route required for mobile checkout');

        $org = \App\Models\Organization::query()->findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_pos_order_edit' => true,
        ]);
        $org->update(['module_settings' => $settings]);

        $rep = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'mobile_rep_'.uniqid(),
            'email' => null,
            'password' => bcrypt('password'),
            'full_name' => 'Field Rep',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['mobile'],
            'mobile_order_scope' => 'route_only',
            'assigned_route_id' => $routeId,
            'is_active' => true,
        ]);

        Sanctum::actingAs($rep);
        $repCartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $rep->branch_id,
            'route_id' => $routeId,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$repCartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $original = $this->postJson("/api/v1/sales/carts/{$repCartId}/checkout", [
            'save_only' => true,
            'pay_now' => 0,
            'route_id' => $routeId,
        ])->assertCreated()->json();

        $originalSale = Sale::query()->findOrFail($original['id']);
        $this->assertSame((int) $rep->id, (int) $originalSale->cashier_id);
        $originalTotal = (float) ($originalSale->order_total ?? 0);

        // Office edits the rep's unpaid mobile order (restore → revise → checkout).
        Sanctum::actingAs($admin);
        $editCart = $this->postJson("/api/v1/sales/orders/{$original['id']}/restore-to-cart", [
            'replace' => true,
        ])->assertOk()->json();

        $this->assertSame((int) $original['id'], (int) ($editCart['superseded_sale_id'] ?? 0));

        $this->postJson("/api/v1/sales/carts/{$editCart['id']}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 2,
        ])->assertCreated();

        $edited = $this->postJson("/api/v1/sales/carts/{$editCart['id']}/checkout", [
            'save_only' => true,
            'pay_now' => 0,
            'route_id' => $routeId,
        ])->assertCreated()->json();

        $editedSale = Sale::query()->findOrFail($edited['id']);
        $this->assertSame(
            (int) $rep->id,
            (int) $editedSale->cashier_id,
            'Revised sale must stay on the mobile rep so dashboard totals update',
        );
        $meta = is_array($editedSale->fulfillment_meta) ? $editedSale->fulfillment_meta : [];
        $this->assertSame((int) $admin->id, (int) ($meta['edited_by'] ?? 0));
        $this->assertGreaterThan($originalTotal, (float) $editedSale->order_total);

        $superseded = Sale::query()->findOrFail($original['id']);
        $this->assertSame('cancelled', (string) $superseded->status);
        $this->assertSame(1, (int) $superseded->archived);

        Sanctum::actingAs($rep);
        $dashboard = $this->getJson('/api/v1/mobile/dashboard')->assertOk()->json();
        $this->assertSame(1, (int) ($dashboard['summary']['NoofOrders'] ?? 0));
        $this->assertEqualsWithDelta(
            (float) $editedSale->order_total,
            (float) ($dashboard['summary']['orderTotals'] ?? 0),
            0.05,
        );
    }
}
