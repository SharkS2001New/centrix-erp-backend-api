<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KraAgent;
use App\Models\KraAgentCommand;
use App\Models\KraResponse;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\Vat;
use App\Services\Kra\KraAgentBridge;
use App\Services\Sales\CheckoutKraSubmissionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class KraDeviceCheckoutTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);

        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_kra_device' => true,
            'enable_kra_agent' => true,
            'kra_device_ip' => 'http://192.168.1.50:8010',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'default_submit_kra' => true,
            'kra_sales_scope' => 'both',
        ]);
        $org->update(['module_settings' => $settings]);

        // Warm Centrix KRA Agent so checkout fiscalizes through the bridge (not direct HTTP).
        $bridge = app(KraAgentBridge::class);
        $agent = $bridge->resolveOrCreateForOrganization((int) $org->id, $settings['finance']);
        $bridge->touchAgent($agent, '1.3.4-test', true, '');
    }

    protected function fakeKraDeviceHttp(array $workflowBody, int $workflowStatus = 200): void
    {
        config([
            'testing.kra_agent_workflow_response' => $workflowBody,
            'testing.kra_agent_workflow_status' => $workflowStatus,
        ]);
    }

    public function test_kra_agent_reclaims_expired_and_stale_processing_commands(): void
    {
        $agent = KraAgent::query()
            ->where('organization_id', $this->user->organization_id)
            ->firstOrFail();

        $expiredId = (string) Str::uuid();
        KraAgentCommand::query()->create([
            'id' => $expiredId,
            'kra_agent_id' => $agent->id,
            'method' => 'POST',
            'path' => '/api/complete-workflow',
            'status' => 'processing',
            'created_at' => now()->subMinutes(3),
            'expires_at' => now()->subMinute(),
        ]);

        $staleId = (string) Str::uuid();
        KraAgentCommand::query()->create([
            'id' => $staleId,
            'kra_agent_id' => $agent->id,
            'method' => 'POST',
            'path' => '/api/complete-workflow',
            'status' => 'processing',
            'created_at' => now()->subMinutes(3),
            'expires_at' => now()->addMinutes(1),
        ]);

        $bridge = app(KraAgentBridge::class);
        $this->assertSame(2, $bridge->reclaimStaleProcessingCommands($agent));
        $this->assertDatabaseHas('kra_agent_commands', [
            'id' => $expiredId,
            'status' => 'expired',
        ]);
        $this->assertDatabaseHas('kra_agent_commands', [
            'id' => $staleId,
            'status' => 'pending',
        ]);

        $claimed = $bridge->pullPendingCommands(
            $agent,
            touch: false,
            reclaim: false,
        );
        $this->assertSame($staleId, $claimed[0]['id'] ?? null);
        $this->assertSame([], $bridge->pullPendingCommands(
            $agent,
            touch: false,
            reclaim: false,
        ));
        $this->assertDatabaseHas('kra_agent_commands', [
            'id' => $staleId,
            'status' => 'processing',
        ]);
    }

    public function test_checkout_submits_to_kra_device_when_enabled(): void
    {
        $this->fakeKraDeviceHttp([
                'success' => true,
                'message' => 'OK',
                'invoice_number' => 'CU-12345',
                'Receipt Signature' => 'SIG-ABC',
                'signature_link' => 'https://example.test/qr',
                'serial_number' => 'DEJA02220240050',
                'timestamp' => '2026-06-11T12:00:00',
        ]);

        $product = Product::with('vat')->first();
        if (! $product->vat_id) {
            $vat = Vat::first();
            $product->update(['vat_id' => $vat->id]);
        }

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
        ])->assertCreated()->json();

        $this->assertDatabaseHas('kra_responses', [
            'sale_id' => $sale['id'],
            'status' => 'success',
        ]);

        $this->assertArrayHasKey('kra_response', $sale);
        $this->assertSame('https://example.test/qr', $sale['kra_response']['signature_link'] ?? null);
        $this->assertSame('CU-12345', $sale['kra_response']['invoice_number'] ?? null);

        $this->assertTrue(
            KraAgentCommand::query()->where('path', '/api/complete-workflow')->exists(),
            'Checkout should fiscalize via Centrix KRA Agent complete-workflow',
        );
        Http::assertNothingSent();
    }

    public function test_mobile_unpaid_checkout_schedules_kra_fiscalization_after_checkout(): void
    {
        $this->fakeKraDeviceHttp([
            'success' => true,
            'message' => 'OK',
            'invoice_number' => 'CU-MOBILE',
            'Receipt Signature' => 'SIG-MOB',
            'signature_link' => 'https://example.test/qr-mobile',
            'serial_number' => 'DEJA02220240050',
            'timestamp' => '2026-06-11T12:00:00',
        ]);

        $product = Product::with('vat')->first();
        if (! $product->vat_id) {
            $product->update(['vat_id' => Vat::first()->id]);
        }
        $product->update(['product_name' => 'Maziwa 🥛 – 1L']);

        $template = Sale::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('channel', 'mobile')
            ->whereNotNull('route_id')
            ->whereNotNull('customer_num')
            ->first();
        $this->assertNotNull($template, 'Demo org needs a mobile sale with route + customer');

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $this->user->branch_id,
            'route_id' => (int) $template->route_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 0.333,
            'unit_price' => (float) $product->unit_price + 500,
            'amount' => ((float) $product->unit_price + 500) * 0.333,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
            'customer_num' => (int) $template->customer_num,
            'save_only' => true,
            'pay_now' => 0,
        ])->assertCreated()->json();

        $this->assertDatabaseHas('kra_responses', [
            'sale_id' => $sale['id'],
            'status' => 'success',
        ]);
        $this->assertTrue(
            KraAgentCommand::query()->where('path', '/api/complete-workflow')->exists(),
            'Mobile checkout must fiscalize through Centrix KRA Agent',
        );
        $command = KraAgentCommand::query()
            ->where('path', '/api/complete-workflow')
            ->latest('created_at')
            ->firstOrFail();
        $saleItem = SaleItem::query()->where('sale_id', $sale['id'])->firstOrFail();
        $pluLine = $command->body_json['plu_data'][0] ?? [];
        $sign = $command->body_json['sign_structure'] ?? [];
        $this->assertSame('Maziwa - 1L', $pluLine['item_Name'] ?? null);
        $this->assertSame('0.333', $pluLine['SaleQty'] ?? null);
        $this->assertSame((string) $pluLine['SaleAmount'], $sign['CashAmt'] ?? null);
        $this->assertSame('0.00', $sign['CheckAmt'] ?? null);
        $this->assertSame('0.00', $sign['CardAmt'] ?? null);
        $this->assertNotEquals((float) $product->unit_price + 500, (float) $saleItem->unit_price);
        $this->assertEqualsWithDelta(
            (float) $saleItem->amount,
            (float) ($pluLine['SalePrice'] ?? 0) * (float) ($pluLine['SaleQty'] ?? 0),
            0.0001,
        );
        Http::assertNothingSent();
    }

    public function test_mobile_cart_uses_server_pricing_instead_of_client_computed_unit_price(): void
    {
        $product = Product::with('vat')->firstOrFail();
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $this->user->branch_id,
        ])->assertCreated()->json('id');

        $clientUnitPrice = (float) $product->unit_price + 500;
        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => $clientUnitPrice,
            'amount' => $clientUnitPrice,
            'product_vat' => $clientUnitPrice,
        ])->assertCreated();

        $line = $this->getJson("/api/v1/sales/carts/{$cartId}")
            ->assertOk()
            ->json('lines.0');

        $this->assertNotEquals($clientUnitPrice, (float) $line['unit_price']);
        $this->assertNotEquals($clientUnitPrice, (float) $line['amount']);
        $this->assertNotEquals($clientUnitPrice, (float) $line['product_vat']);

        $this->patchJson("/api/v1/sales/carts/{$cartId}/lines/{$line['update_code']}", [
            'quantity' => 1,
            'unit_price' => $clientUnitPrice,
            'amount' => $clientUnitPrice,
            'product_vat' => $clientUnitPrice,
        ])->assertOk();

        $updatedLine = $this->getJson("/api/v1/sales/carts/{$cartId}")
            ->assertOk()
            ->json('lines.0');
        $this->assertNotEquals($clientUnitPrice, (float) $updatedLine['unit_price']);
        $this->assertNotEquals($clientUnitPrice, (float) $updatedLine['amount']);
        $this->assertNotEquals($clientUnitPrice, (float) $updatedLine['product_vat']);
    }

    public function test_checkout_skips_kra_when_device_disabled(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance']['enable_kra_device'] = false;
        $org->update(['module_settings' => $settings]);

        $productCode = Product::first()->product_code;
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ]);

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
        ])->assertCreated()->json();

        $this->assertDatabaseMissing('kra_responses', [
            'sale_id' => $sale['id'],
        ]);
    }

    public function test_mobile_only_scope_fiscalizes_mobile_orders_and_skips_pos(): void
    {
        $this->fakeKraDeviceHttp([
            'success' => true,
            'message' => 'OK',
            'invoice_number' => 'CU-MOBILE-SCOPE',
            'Receipt Signature' => 'SIG-MOB-SCOPE',
            'signature_link' => 'https://example.test/qr-mobile-scope',
            'serial_number' => 'DEJA02220240050',
            'timestamp' => '2026-06-11T12:00:00',
        ]);

        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = \App\Services\Kra\KraFiscalPolicy::applySalesScope(
            $settings['finance'] ?? [],
            \App\Services\Kra\KraFiscalPolicy::SCOPE_MOBILE,
        );
        $org->update(['module_settings' => $settings]);

        $product = Product::with('vat')->firstOrFail();
        if (! $product->vat_id) {
            $product->update(['vat_id' => Vat::first()->id]);
        }

        $template = Sale::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('channel', 'mobile')
            ->whereNotNull('route_id')
            ->whereNotNull('customer_num')
            ->first();
        $this->assertNotNull($template, 'Demo org needs a mobile sale with route + customer');

        $mobileCartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $this->user->branch_id,
            'route_id' => (int) $template->route_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$mobileCartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        // Field sales does not send submit_kra — org Mobile-only scope must still fiscalize.
        $mobileSale = $this->postJson("/api/v1/sales/carts/{$mobileCartId}/checkout", [
            'status' => 'completed',
            'customer_num' => (int) $template->customer_num,
            'save_only' => true,
            'pay_now' => 0,
        ])->assertCreated()->json();

        $this->assertSame(
            'mobile',
            Sale::query()->findOrFail($mobileSale['id'])->channel,
        );
        $this->assertDatabaseHas('kra_responses', [
            'sale_id' => $mobileSale['id'],
            'status' => 'success',
        ]);

        $posCartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$posCartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $posSale = $this->postJson("/api/v1/sales/carts/{$posCartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
        ])->assertCreated()->json();

        $this->assertDatabaseMissing('kra_responses', [
            'sale_id' => $posSale['id'],
        ]);
    }

    public function test_erp_only_scope_fiscalizes_pos_and_skips_mobile(): void
    {
        $this->fakeKraDeviceHttp([
            'success' => true,
            'message' => 'OK',
            'invoice_number' => 'CU-ERP-SCOPE',
            'Receipt Signature' => 'SIG-ERP-SCOPE',
            'signature_link' => 'https://example.test/qr-erp-scope',
            'serial_number' => 'DEJA02220240050',
            'timestamp' => '2026-06-11T12:00:00',
        ]);

        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = \App\Services\Kra\KraFiscalPolicy::applySalesScope(
            $settings['finance'] ?? [],
            \App\Services\Kra\KraFiscalPolicy::SCOPE_ERP,
        );
        $org->update(['module_settings' => $settings]);

        $product = Product::with('vat')->firstOrFail();
        if (! $product->vat_id) {
            $product->update(['vat_id' => Vat::first()->id]);
        }

        $posCartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$posCartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $posSale = $this->postJson("/api/v1/sales/carts/{$posCartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
        ])->assertCreated()->json();

        $this->assertSame('pos', Sale::query()->findOrFail($posSale['id'])->channel);
        $this->assertDatabaseHas('kra_responses', [
            'sale_id' => $posSale['id'],
            'status' => 'success',
        ]);

        $template = Sale::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('channel', 'mobile')
            ->whereNotNull('route_id')
            ->whereNotNull('customer_num')
            ->first();
        $this->assertNotNull($template, 'Demo org needs a mobile sale with route + customer');

        $mobileCartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $this->user->branch_id,
            'route_id' => (int) $template->route_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$mobileCartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $mobileSale = $this->postJson("/api/v1/sales/carts/{$mobileCartId}/checkout", [
            'status' => 'completed',
            'customer_num' => (int) $template->customer_num,
            'save_only' => true,
            'pay_now' => 0,
            'submit_kra' => true,
        ])->assertCreated()->json();

        $this->assertDatabaseMissing('kra_responses', [
            'sale_id' => $mobileSale['id'],
        ]);
    }

    public function test_both_scope_fiscalizes_mobile_and_pos(): void
    {
        $this->fakeKraDeviceHttp([
            'success' => true,
            'message' => 'OK',
            'invoice_number' => 'CU-BOTH-SCOPE',
            'Receipt Signature' => 'SIG-BOTH-SCOPE',
            'signature_link' => 'https://example.test/qr-both-scope',
            'serial_number' => 'DEJA02220240050',
            'timestamp' => '2026-06-11T12:00:00',
        ]);

        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = \App\Services\Kra\KraFiscalPolicy::applySalesScope(
            $settings['finance'] ?? [],
            \App\Services\Kra\KraFiscalPolicy::SCOPE_BOTH,
        );
        $org->update(['module_settings' => $settings]);

        $product = Product::with('vat')->firstOrFail();
        if (! $product->vat_id) {
            $product->update(['vat_id' => Vat::first()->id]);
        }

        $posCartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$posCartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $posSale = $this->postJson("/api/v1/sales/carts/{$posCartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
        ])->assertCreated()->json();

        $this->assertDatabaseHas('kra_responses', [
            'sale_id' => $posSale['id'],
            'status' => 'success',
        ]);

        $template = Sale::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('channel', 'mobile')
            ->whereNotNull('route_id')
            ->whereNotNull('customer_num')
            ->first();
        $this->assertNotNull($template, 'Demo org needs a mobile sale with route + customer');

        $mobileCartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'mobile',
            'branch_id' => $this->user->branch_id,
            'route_id' => (int) $template->route_id,
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/sales/carts/{$mobileCartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $mobileSale = $this->postJson("/api/v1/sales/carts/{$mobileCartId}/checkout", [
            'status' => 'completed',
            'customer_num' => (int) $template->customer_num,
            'save_only' => true,
            'pay_now' => 0,
        ])->assertCreated()->json();

        $this->assertDatabaseHas('kra_responses', [
            'sale_id' => $mobileSale['id'],
            'status' => 'success',
        ]);
    }

    public function test_checkout_skips_kra_when_fiscalization_turned_off(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = \App\Services\Kra\KraFiscalPolicy::applySalesScope(
            $settings['finance'] ?? [],
            \App\Services\Kra\KraFiscalPolicy::SCOPE_OFF,
        );
        $org->update(['module_settings' => $settings]);

        $productCode = Product::first()->product_code;
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ]);

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
        ])->assertCreated()->json();

        $this->assertDatabaseMissing('kra_responses', [
            'sale_id' => $sale['id'],
        ]);

        Http::assertNothingSent();
    }

    public function test_checkout_skips_kra_when_order_total_meets_bypass_threshold(): void
    {
        Http::fake();

        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance']['kra_bypass_above_amount'] = 100;
        $org->update(['module_settings' => $settings]);

        $product = Product::with('vat')->first();
        if (! $product->vat_id) {
            $product->update(['vat_id' => Vat::first()->id]);
        }

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 5,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
        ])->assertCreated()->json();

        $this->assertGreaterThanOrEqual(100, (float) $sale['order_total']);
        $this->assertTrue((bool) ($sale['kra_skipped'] ?? false));
        $this->assertStringContainsString(
            'amount bypass',
            strtolower((string) ($sale['kra_warning'] ?? '')),
        );
        $this->assertDatabaseHas('kra_responses', [
            'sale_id' => $sale['id'],
            'status' => 'skipped',
        ]);
        $row = \App\Models\KraResponse::query()->where('sale_id', $sale['id'])->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('amount bypass', strtolower((string) $row->error_message));

        Http::assertNothingSent();
    }

    public function test_checkout_saves_sale_when_kra_device_fails(): void
    {
        $this->fakeKraDeviceHttp([
            'success' => false,
            'message' => 'Device rejected sale',
        ]);

        $product = Product::with('vat')->first();
        if (! $product->vat_id) {
            $product->update(['vat_id' => Vat::first()->id]);
        }

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $beforeSales = \App\Models\Sale::query()->count();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('kra_skipped', true)
            ->assertJsonPath('kra_warning', 'Device rejected sale')
            ->assertJsonPath('kra_error_detail', 'Device rejected sale')
            ->json();

        $this->assertSame($beforeSales + 1, \App\Models\Sale::query()->count());
        $this->assertDatabaseHas('kra_responses', [
            'sale_id' => $sale['id'],
            'status' => 'failed',
        ]);
        $this->assertDatabaseMissing('temporary_carts', ['id' => $cartId]);
    }

    public function test_held_checkout_does_not_submit_to_kra_device(): void
    {
        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => true,
                'message' => 'OK',
                'invoice_number' => 'CU-HOLD',
                'Receipt Signature' => 'SIG-HOLD',
                'signature_link' => 'https://example.test/qr-hold',
                'serial_number' => 'DEJA02220240050',
                'timestamp' => '2026-06-11T12:00:00',
            ], 200),
        ]);

        $product = Product::with('vat')->first();
        $this->assertNotNull($product);
        if (! $product->vat_id) {
            $vat = Vat::first();
            $product->update(['vat_id' => $vat->id]);
        }

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->assertSuccessful()->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertSuccessful();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'held',
            'save_only' => true,
            'pay_now' => 0,
            'customer_name_override' => 'Walk-in',
        ])->assertCreated()->json();

        $this->assertSame('held', $sale['status']);
        $this->assertDatabaseMissing('kra_responses', [
            'sale_id' => $sale['id'],
        ]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '192.168.1.50'));
    }

    public function test_checkout_submits_buyer_kra_pin_from_linked_customer(): void
    {
        $this->fakeKraDeviceHttp([
            'success' => true,
            'message' => 'OK',
            'invoice_number' => 'CU-BUYER-PIN',
            'Receipt Signature' => 'SIG-PIN',
            'signature_link' => 'https://example.test/qr-pin',
            'serial_number' => 'DEJA02220240050',
            'timestamp' => '2026-06-11T12:00:00',
        ]);

        $product = Product::with('vat')->first();
        if (! $product->vat_id) {
            $product->update(['vat_id' => Vat::first()->id]);
        }

        $max = (int) Customer::query()->max('customer_num');
        $customer = Customer::create([
            'customer_num' => $max + 1,
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'customer_name' => 'PIN Customer Ltd',
            'customer_type' => 'regular',
            'kra_pin' => 'P051234567X',
            'phone_number' => '0712345678',
            'created_by' => $this->user->id,
        ]);

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
            'customer_num' => $customer->customer_num,
            'pos_order_num' => 42,
            'pos_order_date' => now()->toDateString(),
        ])->assertCreated()->json();

        $command = KraAgentCommand::query()
            ->where('path', '/api/complete-workflow')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($command);
        $sign = is_array($command->body_json) ? ($command->body_json['sign_structure'] ?? []) : [];
        $this->assertSame('P051234567X', $sign['pinOfBuyer'] ?? null);
        $saleItem = SaleItem::query()->where('sale_id', $sale['id'])->firstOrFail();
        $this->assertSame(number_format((float) $saleItem->amount, 2, '.', ''), $sign['CashAmt'] ?? null);
        $this->assertSame('0.00', $sign['CheckAmt'] ?? null);
        $this->assertSame('0.00', $sign['CardAmt'] ?? null);

        $this->assertDatabaseHas('kra_responses', [
            'order_no' => 42,
            'status' => 'success',
        ]);
    }

    public function test_offline_order_checkout_does_not_fiscalize(): void
    {
        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => true,
                'message' => 'OK',
                'invoice_number' => 'CU-OFFLINE',
                'Receipt Signature' => 'SIG-OFF',
                'signature_link' => 'https://example.test/qr-off',
                'serial_number' => 'DEJA02220240050',
                'timestamp' => '2026-06-11T12:00:00',
            ], 200),
        ]);

        $product = Product::with('vat')->first();
        if (! $product->vat_id) {
            $product->update(['vat_id' => Vat::first()->id]);
        }

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $product->product_code,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
            'pos_order_num' => 99,
            'pos_order_date' => now()->toDateString(),
            'offline_order' => true,
            'client_sale_uuid' => 'offline-no-kra-'.uniqid(),
        ])->assertCreated();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/complete-workflow'));
        $this->assertDatabaseMissing('kra_responses', [
            'order_no' => 99,
        ]);
    }

    public function test_kra_success_after_soft_fail_updates_same_invoice_row(): void
    {
        $sale = Sale::query()->where('organization_id', $this->user->organization_id)->firstOrFail();
        $invoiceNumber = '1787'.random_int(100000, 999999);

        $service = app(CheckoutKraSubmissionService::class);
        $persist = new \ReflectionMethod(CheckoutKraSubmissionService::class, 'persistResponse');

        $failed = $persist->invoke($service, $sale, [
            'order_no' => (int) $sale->order_num,
            'invoice_number' => $invoiceNumber,
            'status' => 'failed',
            'error_message' => 'Device busy',
            'request_payload' => [
                'sign_structure' => ['TraderSystemInvoiceNumber' => $invoiceNumber],
            ],
        ]);

        $updated = $persist->invoke($service, $sale, [
            'order_no' => (int) $sale->order_num,
            'invoice_number' => $invoiceNumber,
            'receipt_signature' => 'E3C5-5DZD-NKDS-GE76',
            'signature_link' => 'https://etims.kra.go.ke/example',
            'serial_number' => 'DEJA02220240050',
            'status' => 'success',
            'error_message' => null,
            'request_payload' => [
                'sign_structure' => ['TraderSystemInvoiceNumber' => $invoiceNumber],
            ],
        ]);

        $this->assertSame($failed->id, $updated->id);
        $this->assertSame('success', $updated->fresh()->status);
        $this->assertSame(1, KraResponse::query()->where('sale_id', $sale->id)->where('invoice_number', $invoiceNumber)->count());
    }

    public function test_kra_persist_reclaims_legacy_pos_invoice_from_superseded_sale(): void
    {
        $orgId = (int) $this->user->organization_id;
        $prior = Sale::query()->where('organization_id', $orgId)->firstOrFail();
        $orderNum = (int) $prior->order_num;
        $legacyInvoice = 'POS-'.$orderNum;

        KraResponse::create([
            'sale_id' => $prior->id,
            'organization_id' => $orgId,
            'order_no' => $orderNum,
            'invoice_number' => $legacyInvoice,
            'status' => 'failed',
            'error_message' => 'Device busy',
        ]);

        $prior->update([
            'order_num' => 9_000_000 + (int) $prior->id,
            'status' => 'cancelled',
            'fulfillment_meta' => array_merge(
                is_array($prior->fulfillment_meta) ? $prior->fulfillment_meta : [],
                ['superseded_by_edit' => true, 'original_order_num' => $orderNum],
            ),
        ]);

        $revised = Sale::query()->create([
            'organization_id' => $orgId,
            'branch_id' => $prior->branch_id,
            'order_num' => $orderNum,
            'channel' => 'pos',
            'cashier_id' => $this->user->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 100,
            'total_vat' => 0,
            'amount_paid' => 100,
            'archived' => 0,
            'completed_at' => now(),
        ]);

        $service = app(CheckoutKraSubmissionService::class);
        $persist = new \ReflectionMethod(CheckoutKraSubmissionService::class, 'persistResponse');

        // Mimic the production collision: revised sale soft-fails with legacy POS-{order_num}.
        $row = $persist->invoke($service, $revised, [
            'order_no' => $orderNum,
            'invoice_number' => $legacyInvoice,
            'status' => 'failed',
            'error_message' => 'Device busy again',
        ]);

        $this->assertSame((int) $revised->id, (int) $row->sale_id);
        $this->assertSame($legacyInvoice, $row->invoice_number);
        $this->assertSame(1, KraResponse::query()->where('organization_id', $orgId)->where('invoice_number', $legacyInvoice)->count());
    }
}
