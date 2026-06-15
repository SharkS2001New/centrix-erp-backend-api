<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class KraProductRegistrationTest extends TestCase
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
            'kra_plu_register_path' => '/api/register-plu',
        ]);
        $org->update(['module_settings' => $settings]);
    }

    public function test_register_products_posts_lightstores_plu_payload(): void
    {
        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => true,
                'message' => 'PLU registered',
            ], 200),
        ]);

        $product = Product::firstOrFail();

        $response = $this->postJson('/api/v1/kra/register-products', [
            'product_codes' => [$product->product_code],
        ])->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame(1, $response->json('registered_count'));

        Http::assertSent(function ($request) use ($product) {
            if (! str_contains($request->url(), '/api/register-plu')) {
                return false;
            }

            $body = $request->data();
            $plu = $body['plu_data'][0] ?? [];
            $sign = $body['sign_structure'] ?? null;

            return ($body['sn'] ?? '') === 'DEJA02220240050'
                && ($body['is_test'] ?? null) !== null
                && is_array($body['plu_data'])
                && ($plu['Barcode'] ?? '') === $product->product_code
                && ($plu['item_Name'] ?? '') === $product->product_name
                && ($plu['ItemDisCount(%)'] ?? '') === '0'
                && array_is_list($sign) === false
                && ($sign['pinOfshop'] ?? '') === 'P052177271G'
                && ($sign['SignType'] ?? '') === '2';
        });
    }

    public function test_register_products_requires_kra_device_enabled(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance']['enable_kra_device'] = false;
        $org->update(['module_settings' => $settings]);

        $product = Product::firstOrFail();

        $this->postJson('/api/v1/kra/register-products', [
            'product_codes' => [$product->product_code],
        ])->assertStatus(422);
    }
}
