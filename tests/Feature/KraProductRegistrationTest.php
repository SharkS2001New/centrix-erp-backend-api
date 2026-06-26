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

    public function test_register_products_posts_upload_plu_data_payload(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance']['kra_plu_register_path'] = '/api/upload-plu-data';
        $org->update(['module_settings' => $settings]);

        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => true,
                'message' => 'Successfully uploaded 1 PLU items to device',
                'items_processed' => 1,
            ], 200),
        ]);

        $product = Product::with(['vat', 'unit'])->firstOrFail();

        $response = $this->postJson('/api/v1/kra/register-products', [
            'product_codes' => [$product->product_code],
        ])->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame(1, $response->json('registered_count'));

        Http::assertSent(function ($request) use ($product) {
            if (! str_contains($request->url(), '/api/upload-plu-data')) {
                return false;
            }

            $body = $request->data();
            $plu = $body['plu_items'][0] ?? [];

            return ($body['sn'] ?? '') === 'DEJA02220240050'
                && is_array($body['plu_items'])
                && count($body['plu_items']) === 1
                && ($body['from_no'] ?? null) === 1
                && ($body['end_no'] ?? null) === 100000
                && ($body['update_flag'] ?? null) === 0
                && ($body['file_signal'] ?? null) === ''
                && ! array_key_exists('sign_structure', $body)
                && ! array_key_exists('plu_data', $body)
                && ! array_key_exists('PluItems', $body)
                && ($plu['barcode'] ?? '') === '000000'.$product->product_code
                && ($plu['plu_name'] ?? '') === $product->product_name
                && ($plu['tax_type'] ?? '') === 'B-16.00%'
                && ($plu['type_code'] ?? '') === '02Finished Product'
                && ($plu['use_yor_n'] ?? '') === '1';
        });

        Http::assertSentCount(1);
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
