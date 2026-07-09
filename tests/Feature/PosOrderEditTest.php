<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\CustomerReturn;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PosOrderEditTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected string $productCodeA;

    protected string $productCodeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);

        $products = Product::query()->limit(2)->get();
        $this->productCodeA = $products[0]->product_code;
        $this->productCodeB = $products[1]->product_code;
    }

    public function test_completed_pos_order_restore_blocked_when_flag_off(): void
    {
        $this->setPosOrderEditEnabled(false);

        $sale = $this->completePosSale($this->productCodeA, 1);

        $this->postJson("/api/v1/sales/orders/{$sale['id']}/restore-to-cart", [
            'replace' => true,
        ])->assertStatus(422);
    }

    public function test_completed_pos_order_can_be_restored_when_flag_on(): void
    {
        $this->setPosOrderEditEnabled(true);

        $sale = $this->completePosSale($this->productCodeA, 2);

        $cart = $this->postJson("/api/v1/sales/orders/{$sale['id']}/restore-to-cart", [
            'replace' => true,
        ])->assertOk()->json();

        $this->assertCount(1, $cart['lines'] ?? []);
        $this->assertEquals(2.0, (float) ($cart['lines'][0]['quantity'] ?? 0));
        $this->assertEquals('cancelled', Sale::find($sale['id'])->status);
        $this->assertEquals((int) $sale['order_num'], $cart['held_order_num'] ?? null);
    }

    public function test_pos_edit_swaps_product_and_restores_stock_correctly(): void
    {
        $this->setPosOrderEditEnabled(true);

        $beforeA = $this->onHandShop($this->productCodeA);
        $beforeB = $this->onHandShop($this->productCodeB);

        $sale = $this->completePosSale($this->productCodeA, 3);
        $this->assertEquals($beforeA - 3, $this->onHandShop($this->productCodeA));

        $cart = $this->postJson("/api/v1/sales/orders/{$sale['id']}/restore-to-cart", [
            'replace' => true,
        ])->assertOk()->json();

        $this->assertEquals($beforeA, $this->onHandShop($this->productCodeA));

        $lineRef = $cart['lines'][0]['update_code'] ?? $cart['lines'][0]['id'] ?? null;
        $this->assertNotEmpty($lineRef);

        $this->deleteJson("/api/v1/sales/carts/{$cart['id']}/lines/{$lineRef}")
            ->assertOk();

        $this->postJson("/api/v1/sales/carts/{$cart['id']}/lines", [
            'product_code' => $this->productCodeB,
            'quantity' => 2,
        ])->assertCreated();

        $newSale = $this->postJson("/api/v1/sales/carts/{$cart['id']}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
        ])->assertCreated()->json();

        $this->assertEquals('completed', $newSale['status']);
        $this->assertEquals((int) $sale['order_num'], (int) $newSale['order_num']);
        $this->assertEquals($beforeA, $this->onHandShop($this->productCodeA));
        $this->assertEquals($beforeB - 2, $this->onHandShop($this->productCodeB));
    }

    public function test_pos_edit_creates_kra_credit_note_when_sale_was_fiscalized(): void
    {
        $this->setPosOrderEditEnabled(true);
        $this->enableKraDevice();

        Http::fake([
            '192.168.1.50:8010/*' => Http::sequence()
                ->push([
                    'success' => true,
                    'message' => 'OK',
                    'invoice_number' => 'CU-EDIT-001',
                    'Receipt Signature' => 'SIG-EDIT',
                    'signature_link' => 'https://example.test/qr',
                    'serial_number' => 'DEJA02220240050',
                    'timestamp' => '2026-06-11T12:00:00',
                ], 200)
                ->push([
                    'success' => true,
                    'message' => 'Credit note OK',
                    'invoice_number' => 'CN-EDIT-001',
                ], 200),
        ]);

        $sale = $this->completePosSale($this->productCodeA, 1, ['submit_kra' => true]);

        $this->assertDatabaseHas('kra_responses', [
            'sale_id' => $sale['id'],
            'status' => 'success',
        ]);

        $this->postJson("/api/v1/sales/orders/{$sale['id']}/restore-to-cart", [
            'replace' => true,
        ])->assertOk();

        $this->assertDatabaseHas('customer_returns', [
            'sale_id' => $sale['id'],
            'return_kind' => 'pos_edit',
            'status' => 'approved',
        ]);

        $return = CustomerReturn::query()
            ->where('sale_id', $sale['id'])
            ->where('return_kind', 'pos_edit')
            ->first();

        $this->assertNotNull($return);
        $this->assertDatabaseHas('credit_notes', [
            'customer_return_id' => $return->id,
        ]);
    }

    public function test_manager_with_order_edit_permission_can_restore_another_users_booked_order(): void
    {
        $cashier = $this->createSalesUser('pos_edit_cashier', [
            'sales.orders.create',
            'pos.checkout.create',
            'pos.terminal.view',
        ]);
        $manager = $this->createSalesUser('pos_edit_manager', [
            'sales.orders.create',
            'sales.orders.edit',
            'pos.checkout.create',
            'pos.terminal.view',
        ]);

        Sanctum::actingAs($cashier);

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'backend',
            'order_source' => 'backoffice',
            'branch_id' => $cashier->branch_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCodeA,
            'quantity' => 2,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'save_only' => true,
        ])->assertCreated()->json();

        $this->assertSame('booked', $sale['status']);
        $this->assertSame($cashier->id, (int) $sale['cashier_id']);

        Sanctum::actingAs($manager);

        $cart = $this->postJson("/api/v1/sales/orders/{$sale['id']}/restore-to-cart", [
            'replace' => true,
        ])->assertOk()->json();

        $this->assertCount(1, $cart['lines'] ?? []);
        $this->assertEquals('cancelled', Sale::find($sale['id'])->status);
    }

    public function test_capabilities_expose_pos_order_edit_flag(): void
    {
        $this->setPosOrderEditEnabled(true);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('pos_order_edit_enabled', true)
            ->assertJsonPath('module_settings.sales.enable_pos_order_edit', true);
    }

    public function test_platform_sales_config_persists_enable_pos_order_edit(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $orgId = (int) Organization::query()->where('company_code', 'DEMO')->value('id');

        $this->patchJson("/api/v1/admin/organizations/{$orgId}", [
            'sales_platform' => ['enable_pos_order_edit' => true],
        ])->assertOk()
            ->assertJsonPath('sales_platform.enable_pos_order_edit', true);

        $org = Organization::findOrFail($orgId);
        $this->assertTrue((bool) ($org->module_settings['sales']['enable_pos_order_edit'] ?? false));

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('pos_order_edit_enabled', true);
    }

    /** @param array<string, mixed> $checkoutExtra */
    protected function completePosSale(string $productCode, float $quantity, array $checkoutExtra = []): array
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => $quantity,
        ])->assertCreated();

        return $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", array_merge([
            'status' => 'completed',
            'payment_method_code' => 'CASH',
        ], $checkoutExtra))->assertCreated()->json();
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

    protected function enableKraDevice(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_kra_device' => true,
            'kra_device_ip' => 'http://192.168.1.50:8010',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'default_submit_kra' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function onHandShop(string $productCode): float
    {
        return (float) CurrentStock::where('product_code', $productCode)
            ->where('branch_id', $this->user->branch_id)
            ->value('shop_quantity');
    }

    /** @param list<string> $permissionCodes */
    protected function createSalesUser(string $username, array $permissionCodes): User
    {
        $role = Role::query()->firstOrCreate(
            ['role_name' => 'POS Edit Test '.md5($username)],
            ['scope' => 'branch', 'is_active' => true],
        );

        $permissionIds = Permission::query()
            ->whereIn('permission_code', $permissionCodes)
            ->pluck('id')
            ->all();
        $this->assertNotEmpty($permissionIds);

        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => (int) $permissionId,
            ]);
        }

        return User::query()->firstOrCreate(
            ['username' => $username],
            [
                'organization_id' => $this->user->organization_id,
                'branch_id' => $this->user->branch_id,
                'role_id' => $role->id,
                'email' => null,
                'password' => $this->user->password,
                'full_name' => $username,
                'is_admin' => false,
                'is_active' => true,
            ],
        );
    }
}
