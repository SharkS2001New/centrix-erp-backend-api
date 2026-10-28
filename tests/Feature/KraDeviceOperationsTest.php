<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Jobs\ReFiscalizeKraInvoicesLiveJob;
use App\Services\Background\BackgroundTaskService;
use App\Services\Kra\KraDeviceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class KraDeviceOperationsTest extends TestCase
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
            'kra_device_ip' => 'https://kramoonstores.example.test',
            'kra_device_hardware_ip' => '192.168.1.39',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
        ]);
        $org->update(['module_settings' => $settings]);
    }

    public function test_device_init_posts_serial_and_hardware_ip(): void
    {
        Http::fake([
            'kramoonstores.example.test/api/init' => Http::response([
                'status' => 'OK',
                'version' => '1.6.1',
            ], 200),
        ]);

        $this->postJson('/api/v1/kra/device-init', [
            'kra_device_ip' => 'https://kramoonstores.example.test',
            'kra_device_hardware_ip' => '192.168.1.39',
            'kra_serial_number' => 'DEJA02220240050',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/api/init')) {
                return false;
            }

            $body = $request->data();

            return ($body['sn'] ?? '') === 'DEJA02220240050'
                && ($body['ip'] ?? '') === '192.168.1.39';
        });
    }

    public function test_direct_comstore_requests_include_the_api_key_header(): void
    {
        Http::fake([
            'kramoonstores.example.test/api/init' => Http::response([
                'success' => true,
                'message' => 'Device initialized.',
            ], 200),
        ]);

        $device = KraDeviceService::fromSettings([
            'kra_device_ip' => 'https://kramoonstores.example.test',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'kra_comstore_api_key' => 'test-comstore-api-key',
        ]);

        $device->initializeDevice('192.168.1.39');

        Http::assertSent(fn ($request) => $request->hasHeader('X-API-KEY', 'test-comstore-api-key'));
    }

    public function test_device_init_requires_hardware_ip_for_hostname_api_url(): void
    {
        $this->postJson('/api/v1/kra/device-init', [
            'kra_device_ip' => 'https://kramoonstores.example.test',
            'kra_device_hardware_ip' => '',
            'kra_serial_number' => 'DEJA02220240050',
        ])->assertStatus(422);
    }

    public function test_device_restart_posts_hardware_ip(): void
    {
        Http::fake([
            'kramoonstores.example.test/api/restart-device' => Http::response([
                'success' => true,
                'message' => 'Device restart initiated.',
                'device_ip' => '192.168.1.39',
            ], 200),
        ]);

        $this->postJson('/api/v1/kra/device-restart', [
            'kra_device_ip' => 'https://kramoonstores.example.test',
            'kra_device_hardware_ip' => '192.168.1.39',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/api/restart-device')) {
                return false;
            }

            return ($request->data()['ip_address'] ?? '') === '192.168.1.39';
        });
    }

    public function test_test_mode_invoice_can_be_re_fiscalized_live_and_keeps_test_payload(): void
    {
        config([
            'testing.kra_agent_workflow_response' => [
                'success' => true,
                'message' => 'Invoice submitted.',
                'invoice_number' => 'CU-LIVE-90001',
                'Receipt Signature' => 'LIVE-SIGNATURE',
                'signature_link' => 'https://etims.example/live/90001',
                'serial_number' => 'DEJA02220240050',
                'timestamp' => now()->toIso8601String(),
            ],
        ]);

        $org = Organization::findOrFail($this->user->organization_id);
        $finance = array_merge($org->module_settings['finance'] ?? [], [
            'enable_kra_device' => true,
            'enable_kra_agent' => false,
            'kra_device_ip' => 'https://kramoonstores.example.test',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'kra_comstore_api_key' => 'test-comstore-api-key',
            'kra_device_test_mode' => false,
        ]);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = $finance;
        $org->update(['module_settings' => $settings]);

        $sale = Sale::create([
            'order_num' => 90001,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $org->id,
            'channel' => 'pos',
            'cashier_id' => $this->user->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 1500,
            'total_vat' => 0,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => 'REPLAY-ITEM',
            'product_name' => 'Replay item',
            'quantity' => 1,
            'selling_price' => 1500,
            'amount' => 1500,
            'product_vat' => 0,
        ]);
        $row = KraResponse::create([
            'sale_id' => $sale->id,
            'organization_id' => $org->id,
            'order_no' => 90001,
            'invoice_number' => '9000000001',
            'status' => 'success',
            'request_payload' => [
                'is_test' => true,
                'sign_structure' => [
                    'SignType' => '0',
                    'InvoiceType' => 'original',
                    'TraderSystemInvoiceNumber' => '9000000001',
                ],
            ],
            'response_payload' => ['document_type' => 'sale', 'signature' => 'TEST-SIGNATURE'],
        ]);

        $this->postJson("/api/v1/kra-responses/{$row->id}/re-fiscalize-live")
            ->assertOk()
            ->assertJsonPath('kra_response.status', 'success')
            ->assertJsonPath('kra_response.invoice_number', 'CU-LIVE-90001')
            ->assertJsonPath(
                'kra_response.response_payload.replaced_test_attempt.response_payload.signature',
                'TEST-SIGNATURE',
            );

        $command = \App\Models\KraAgentCommand::query()
            ->where('path', '/api/complete-workflow')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame(false, $command->body_json['is_test'] ?? null);
        $this->assertSame('1', $command->body_json['sign_structure']['SignType'] ?? null);
        $this->assertNotSame(
            '9000000001',
            $command->body_json['sign_structure']['TraderSystemInvoiceNumber'] ?? null,
        );
        $row->refresh();
        $this->assertSame(false, $row->request_payload['is_test'] ?? null);
        $this->assertSame('1', $row->request_payload['sign_structure']['SignType'] ?? null);
        $this->assertSame('9000000001', $row->response_payload['replaced_test_attempt']['invoice_number'] ?? null);
        $commandCount = \App\Models\KraAgentCommand::query()->count();
        $this->postJson("/api/v1/kra-responses/{$row->id}/re-fiscalize-live")
            ->assertStatus(422);
        $this->assertSame($commandCount, \App\Models\KraAgentCommand::query()->count());
    }

    public function test_live_re_fiscalization_requires_test_mode_to_be_disabled(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_kra_device' => true,
            'kra_device_test_mode' => true,
        ]);
        $org->update(['module_settings' => $settings]);

        $sale = Sale::create([
            'order_num' => 90002,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $org->id,
            'channel' => 'pos',
            'cashier_id' => $this->user->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 1500,
            'total_vat' => 0,
        ]);
        $row = KraResponse::create([
            'sale_id' => $sale->id,
            'organization_id' => $org->id,
            'order_no' => 90002,
            'invoice_number' => '9000000002',
            'status' => 'success',
            'request_payload' => [
                'is_test' => true,
                'sign_structure' => ['SignType' => '0', 'InvoiceType' => 'original'],
            ],
            'response_payload' => ['document_type' => 'sale'],
        ]);

        $this->postJson("/api/v1/kra-responses/{$row->id}/re-fiscalize-live")
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Turn off KRA device test mode in Finance settings before re-fiscalizing live.',
            );
        Http::assertNothingSent();
        $this->assertSame('success', $row->fresh()->status);
    }

    public function test_live_replay_candidates_cover_sale_date_range_and_exclude_live_sales(): void
    {
        $org = Organization::findOrFail($this->user->organization_id);
        $saleDate = now()->subDays(10)->toDateString();
        $testSale = Sale::create([
            'order_num' => 90003,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $org->id,
            'channel' => 'pos',
            'cashier_id' => $this->user->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 1500,
            'total_vat' => 0,
            'completed_at' => $saleDate.' 12:00:00',
        ]);
        $alreadyLiveSale = Sale::create([
            'order_num' => 90004,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $org->id,
            'channel' => 'pos',
            'cashier_id' => $this->user->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 1500,
            'total_vat' => 0,
            'completed_at' => $saleDate.' 12:00:00',
        ]);
        $testPayload = [
            'is_test' => true,
            'sign_structure' => ['SignType' => '0', 'InvoiceType' => 'original'],
        ];
        $testResponse = ['document_type' => 'sale'];
        $testRow = KraResponse::create([
            'sale_id' => $testSale->id,
            'organization_id' => $org->id,
            'order_no' => 90003,
            'invoice_number' => '9000000003',
            'status' => 'success',
            'request_payload' => $testPayload,
            'response_payload' => $testResponse,
        ]);
        KraResponse::create([
            'sale_id' => $alreadyLiveSale->id,
            'organization_id' => $org->id,
            'order_no' => 90004,
            'invoice_number' => '9000000004',
            'status' => 'success',
            'request_payload' => $testPayload,
            'response_payload' => $testResponse,
        ]);
        KraResponse::create([
            'sale_id' => $alreadyLiveSale->id,
            'organization_id' => $org->id,
            'order_no' => 90004,
            'invoice_number' => '9000000005',
            'status' => 'success',
            'request_payload' => [
                'is_test' => false,
                'sign_structure' => ['SignType' => '1', 'InvoiceType' => 'original'],
            ],
            'response_payload' => $testResponse,
        ]);

        $this->getJson(
            '/api/v1/kra/live-replay-candidates?from_date='.$saleDate.'&to_date='.$saleDate.'&per_page=200'
        )
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.kra_response_id', $testRow->id)
            ->assertJsonPath('data.0.sale_date', $saleDate);
    }

    public function test_live_replay_date_range_runs_as_a_background_task(): void
    {
        config([
            'testing.kra_agent_workflow_response' => [
                'success' => true,
                'message' => 'Invoice submitted.',
                'invoice_number' => 'CU-BACKGROUND-90005',
                'Receipt Signature' => 'LIVE-SIGNATURE',
            ],
        ]);
        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_kra_device' => true,
            'enable_kra_agent' => false,
            'kra_device_ip' => 'https://kramoonstores.example.test',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'kra_comstore_api_key' => 'test-comstore-api-key',
            'kra_device_test_mode' => false,
        ]);
        $org->update(['module_settings' => $settings]);

        $saleDate = now()->toDateString();
        $sale = Sale::create([
            'order_num' => 90005,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $org->id,
            'channel' => 'pos',
            'cashier_id' => $this->user->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 1500,
            'total_vat' => 0,
            'completed_at' => $saleDate.' 12:00:00',
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => 'BACKGROUND-REPLAY-ITEM',
            'product_name' => 'Background replay item',
            'quantity' => 1,
            'selling_price' => 1500,
            'amount' => 1500,
            'product_vat' => 0,
        ]);
        $row = KraResponse::create([
            'sale_id' => $sale->id,
            'organization_id' => $org->id,
            'order_no' => 90005,
            'invoice_number' => '9000000005',
            'status' => 'success',
            'request_payload' => [
                'is_test' => true,
                'sign_structure' => ['SignType' => '0', 'InvoiceType' => 'original'],
            ],
            'response_payload' => ['document_type' => 'sale'],
        ]);

        Queue::fake();
        $queued = $this->postJson('/api/v1/kra/live-replay-tasks', [
            'from_date' => $saleDate,
            'to_date' => $saleDate,
        ])
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('total', 1);

        $taskId = $queued->json('task_id');
        $task = \App\Models\BackgroundTask::query()->findOrFail($taskId);
        Queue::assertPushed(ReFiscalizeKraInvoicesLiveJob::class);

        (new ReFiscalizeKraInvoicesLiveJob($taskId))->handle(app(BackgroundTaskService::class));

        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame(1, $task->fresh()->result['succeeded'] ?? null);
        $this->assertSame(0, $task->fresh()->result['failed'] ?? null);
        $this->assertSame(
            '9000000005',
            $row->fresh()->response_payload['replaced_test_attempt']['invoice_number'] ?? null,
        );
    }
}
