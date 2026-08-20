<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
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

        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $this->user->organization_id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );

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
            'sync' => true,
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
            'sync' => true,
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
                && ($plu['plu_no'] ?? '') === (string) $product->id
                && ($plu['unit_price'] ?? '') === '1'
                && ($plu['tax_type'] ?? '') === 'B-16.00%'
                && ($plu['type_code'] ?? '') === '02Finished Product'
                && ($plu['use_yor_n'] ?? '') === '1';
        });

        Http::assertSentCount(1);
    }

    public function test_register_products_skips_already_on_device_duplicates(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance']['kra_plu_register_path'] = '/api/upload-plu-data';
        $settings['finance']['kra_plu_upload_batch_size'] = 2;
        $org->update(['module_settings' => $settings]);

        $template = Product::query()->firstOrFail();
        $products = collect([
            $this->makeProductClone($template, 'KRA-SKIP-A', 'KRA Skip Alpha'),
            $this->makeProductClone($template, 'KRA-SKIP-B', 'KRA Skip Beta'),
            $this->makeProductClone($template, 'KRA-SKIP-C', 'KRA Skip Gamma'),
        ]);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            // First batch (2 items) fails as duplicate; one-by-one: first ok, second already exists;
            // second batch (1 item) succeeds.
            if ($calls === 1) {
                return Http::response([
                    'success' => false,
                    'message' => 'E353: THE SAME NAME',
                ], 200);
            }
            if ($calls === 3) {
                return Http::response([
                    'success' => false,
                    'message' => 'E353: THE SAME NAME',
                ], 200);
            }

            return Http::response([
                'success' => true,
                'message' => 'Successfully uploaded PLU items to device',
            ], 200);
        });

        $response = $this->postJson('/api/v1/kra/register-products', [
            'product_codes' => $products->pluck('product_code')->all(),
            'sync' => true,
        ]);
        $response->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame(2, $response->json('registered_count'));
        $this->assertSame(1, $response->json('skipped_count'));
        $this->assertStringContainsString('already on device', strtolower((string) $response->json('message')));
        $this->assertGreaterThanOrEqual(4, $calls);
    }

    public function test_register_products_batches_comstore_plu_uploads(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance']['kra_plu_register_path'] = '/api/upload-plu-data';
        $settings['finance']['kra_plu_upload_batch_size'] = 2;
        $org->update(['module_settings' => $settings]);

        Http::fake([
            '192.168.1.50:8010/*' => Http::response([
                'success' => true,
                'message' => 'Successfully uploaded PLU items to device',
            ], 200),
        ]);

        $template = Product::query()->firstOrFail();
        $products = collect([
            $this->makeProductClone($template, 'KRA-BATCH-A', 'KRA Batch Alpha'),
            $this->makeProductClone($template, 'KRA-BATCH-B', 'KRA Batch Beta'),
            $this->makeProductClone($template, 'KRA-BATCH-C', 'KRA Batch Gamma'),
        ]);

        $response = $this->postJson('/api/v1/kra/register-products', [
            'product_codes' => $products->pluck('product_code')->all(),
            'sync' => true,
        ])->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame(3, $response->json('registered_count'));
        $this->assertSame(0, $response->json('skipped_count'));

        Http::assertSentCount(2);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/upload-plu-data')) {
                return false;
            }

            $count = count($request->data()['plu_items'] ?? []);

            return $count === 2 || $count === 1;
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

    protected function makeProductClone(Product $template, string $code, string $name): Product
    {
        $product = $template->replicate();
        $product->product_code = $code;
        $product->product_name = $name;
        $product->organization_id = $template->organization_id;
        unset($product->id);
        $product->exists = false;
        $product->save();

        return $product->fresh(['vat', 'unit']);
    }
}
