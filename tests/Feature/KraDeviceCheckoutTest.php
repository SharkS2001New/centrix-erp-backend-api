<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Support\Facades\Http;
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
            'kra_device_ip' => 'http://192.168.1.50:8010',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'default_submit_kra' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    public function test_checkout_submits_to_kra_device_when_enabled(): void
    {
        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => true,
                'message' => 'OK',
                'invoice_number' => 'CU-12345',
                'Receipt Signature' => 'SIG-ABC',
                'signature_link' => 'https://example.test/qr',
                'serial_number' => 'DEJA02220240050',
                'timestamp' => '2026-06-11T12:00:00',
            ], 200),
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

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/complete-workflow'));
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

    public function test_checkout_skips_kra_when_fiscalization_turned_off(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance']['default_submit_kra'] = false;
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
        $this->assertDatabaseMissing('kra_responses', [
            'sale_id' => $sale['id'],
        ]);

        Http::assertNothingSent();
    }

    public function test_checkout_rolls_back_sale_when_kra_device_fails(): void
    {
        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => false,
                'message' => 'Device rejected sale',
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

        $beforeSales = \App\Models\Sale::query()->count();
        $beforeKra = \App\Models\KraResponse::query()->count();

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['kra']);

        $this->assertSame($beforeSales, \App\Models\Sale::query()->count());
        $this->assertSame($beforeKra, \App\Models\KraResponse::query()->count());
        $this->assertDatabaseHas('temporary_carts', ['id' => $cartId]);
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
        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => true,
                'message' => 'OK',
                'invoice_number' => 'CU-BUYER-PIN',
                'Receipt Signature' => 'SIG-PIN',
                'signature_link' => 'https://example.test/qr-pin',
                'serial_number' => 'DEJA02220240050',
                'timestamp' => '2026-06-11T12:00:00',
            ], 200),
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

        $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
            'customer_num' => $customer->customer_num,
            'pos_order_num' => 42,
            'pos_order_date' => now()->toDateString(),
            'offline_order' => true,
        ])->assertCreated();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/complete-workflow')) {
                return false;
            }
            $payload = $request->data();
            $sign = $payload['sign_structure'] ?? [];

            return ($sign['pinOfBuyer'] ?? null) === 'P051234567X';
        });

        $this->assertDatabaseHas('kra_responses', [
            'order_no' => 42,
            'status' => 'success',
        ]);
    }
}
