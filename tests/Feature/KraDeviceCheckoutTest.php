<?php

namespace Tests\Feature;

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
}
