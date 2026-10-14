<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\Product;
use App\Models\User;
use App\Services\Kra\KraDeviceService;
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
        $product = Product::firstOrFail();
        $captured = null;
        config([
            'testing.kra_agent_command_handler' => function (string $path, array $body) use (&$captured) {
                if ($path === '/api/register-plu') {
                    $captured = $body;
                }

                return ['status' => 200, 'body' => ['success' => true, 'message' => 'PLU registered']];
            },
        ]);

        $response = $this->postJson('/api/v1/kra/register-products', [
            'product_codes' => [$product->product_code],
            'sync' => true,
        ])->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame(1, $response->json('registered_count'));
        $this->assertIsArray($captured);
        $plu = $captured['plu_data'][0] ?? [];
        $sign = $captured['sign_structure'] ?? null;
        $this->assertSame('DEJA02220240050', $captured['sn'] ?? null);
        $this->assertNotNull($captured['is_test'] ?? null);
        $this->assertSame($product->product_code, $plu['Barcode'] ?? null);
        $this->assertSame($product->product_name, $plu['item_Name'] ?? null);
        $this->assertSame('0', $plu['ItemDisCount(%)'] ?? null);
        $this->assertFalse(array_is_list($sign));
        $this->assertSame('P052177271G', $sign['pinOfshop'] ?? null);
        $this->assertSame('2', $sign['SignType'] ?? null);
    }

    public function test_register_products_posts_upload_plu_data_payload(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance']['kra_plu_register_path'] = '/api/upload-plu-data';
        $org->update(['module_settings' => $settings]);

        $product = Product::with(['vat', 'unit'])->firstOrFail();
        $captured = null;
        config([
            'testing.kra_agent_command_handler' => function (string $path, array $body) use (&$captured) {
                if ($path === '/api/upload-plu-data') {
                    $captured = $body;
                }

                return [
                    'status' => 200,
                    'body' => [
                        'success' => true,
                        'message' => 'Successfully uploaded 1 PLU items to device',
                        'items_processed' => 1,
                    ],
                ];
            },
        ]);

        $response = $this->postJson('/api/v1/kra/register-products', [
            'product_codes' => [$product->product_code],
            'sync' => true,
        ])->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertSame(1, $response->json('registered_count'));
        $this->assertIsArray($captured);
        $plu = $captured['plu_items'][0] ?? [];
        $this->assertSame('DEJA02220240050', $captured['sn'] ?? null);
        $this->assertCount(1, $captured['plu_items'] ?? []);
        $this->assertSame(1, $captured['from_no'] ?? null);
        $this->assertSame(100000, $captured['end_no'] ?? null);
        $this->assertSame(0, $captured['update_flag'] ?? null);
        $this->assertSame('', $captured['file_signal'] ?? null);
        $this->assertArrayNotHasKey('sign_structure', $captured);
        $this->assertArrayNotHasKey('plu_data', $captured);
        $this->assertSame('000000'.$product->product_code, $plu['barcode'] ?? null);
        $this->assertSame(
            KraDeviceService::sanitizeComstorePluName((string) $product->product_name),
            $plu['plu_name'] ?? null,
        );
        $this->assertSame((string) $product->id, $plu['plu_no'] ?? null);
        $this->assertSame('B-16.00%', $plu['tax_type'] ?? null);
        $this->assertSame('02Finished Product', $plu['type_code'] ?? null);
        $this->assertSame('1', $plu['use_yor_n'] ?? null);
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
        config([
            'testing.kra_agent_command_handler' => function (string $path, array $body) use (&$calls) {
                if ($path !== '/api/upload-plu-data') {
                    return ['success' => true, 'message' => 'OK'];
                }
                $calls++;
                // First batch (2 items) fails as duplicate; one-by-one: first ok, second already exists;
                // second batch (1 item) succeeds.
                if ($calls === 1 || $calls === 3) {
                    return [
                        'status' => 200,
                        'body' => ['success' => false, 'message' => 'E353: THE SAME NAME'],
                    ];
                }

                return [
                    'status' => 200,
                    'body' => ['success' => true, 'message' => 'Successfully uploaded PLU items to device'],
                ];
            },
        ]);

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

        $uploadCounts = [];
        config([
            'testing.kra_agent_command_handler' => function (string $path, array $body) use (&$uploadCounts) {
                if ($path === '/api/upload-plu-data') {
                    $uploadCounts[] = count($body['plu_items'] ?? []);
                }

                return [
                    'status' => 200,
                    'body' => ['success' => true, 'message' => 'Successfully uploaded PLU items to device'],
                ];
            },
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
        $this->assertSame(2, count($uploadCounts));
        $this->assertContains(2, $uploadCounts);
        $this->assertContains(1, $uploadCounts);
    }

    public function test_register_products_names_invalid_plu_product_after_batch_isolation(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance']['kra_plu_register_path'] = '/api/upload-plu-data';
        $settings['finance']['kra_plu_upload_batch_size'] = 2;
        $org->update(['module_settings' => $settings]);

        $template = Product::query()->firstOrFail();
        $good = $this->makeProductClone($template, 'KRA-OK-A', 'KRA Valid Alpha');
        $bad = $this->makeProductClone($template, 'KRA-BAD-B', 'KRA Invalid Beta');

        $calls = 0;
        config([
            'testing.kra_agent_command_handler' => function (string $path, array $body) use (&$calls) {
                if ($path !== '/api/upload-plu-data') {
                    return ['success' => true, 'message' => 'OK'];
                }
                $calls++;
                $items = $body['plu_items'] ?? [];
                if (count($items) > 1) {
                    return [
                        'status' => 200,
                        'body' => [
                            'success' => false,
                            'message' => 'SetPluDataInfoEX failed: Error processing PLU data (Code 34)',
                        ],
                    ];
                }

                $name = (string) ($items[0]['plu_name'] ?? '');
                if (str_contains($name, 'Invalid Beta')) {
                    return [
                        'status' => 200,
                        'body' => [
                            'success' => false,
                            'message' => 'SetPluDataInfoEX failed: Error processing PLU data (Code 34)',
                        ],
                    ];
                }

                return [
                    'status' => 200,
                    'body' => ['success' => true, 'message' => 'Successfully uploaded PLU items to device'],
                ];
            },
        ]);

        $response = $this->postJson('/api/v1/kra/register-products', [
            'product_codes' => [$good->product_code, $bad->product_code],
            'sync' => true,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertSame(1, $response->json('registered_count'));
        $this->assertSame(1, $response->json('skipped_count'));
        $message = strtolower((string) $response->json('message'));
        $this->assertStringContainsString('invalid beta', $message);
        $this->assertStringContainsString('kra-bad-b', $message);
        $this->assertGreaterThanOrEqual(2, $calls);
    }

    public function test_register_products_stops_when_the_device_itself_fails(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance']['kra_plu_register_path'] = '/api/upload-plu-data';
        $settings['finance']['kra_plu_upload_batch_size'] = 2;
        $org->update(['module_settings' => $settings]);

        $template = Product::query()->firstOrFail();
        $products = collect([
            $this->makeProductClone($template, 'KRA-DEV-A', 'KRA Device Alpha'),
            $this->makeProductClone($template, 'KRA-DEV-B', 'KRA Device Beta'),
        ]);

        $calls = 0;
        config([
            'testing.kra_agent_command_handler' => function (string $path) use (&$calls) {
                if ($path !== '/api/upload-plu-data') {
                    return ['success' => true, 'message' => 'OK'];
                }
                $calls++;

                return [
                    'status' => 200,
                    'body' => ['success' => false, 'message' => 'Device not initialized'],
                ];
            },
        ]);

        $this->postJson('/api/v1/kra/register-products', [
            'product_codes' => $products->pluck('product_code')->all(),
            'sync' => true,
        ])->assertStatus(422);

        $this->assertSame(1, $calls);
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
