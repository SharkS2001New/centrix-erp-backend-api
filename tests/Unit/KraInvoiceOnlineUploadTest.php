<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\KraAgentCommand;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Models\User;
use App\Services\Kra\KraAgentBridge;
use App\Services\Kra\KraDeviceService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class KraInvoiceOnlineUploadTest extends TestCase
{
    use RefreshesErpDatabase;

    protected int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $this->organizationId = (int) $user->organization_id;
        $settings = [
            'kra_device_ip' => 'http://localhost:4000',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'kra_device_test_mode' => false,
        ];
        $bridge = app(KraAgentBridge::class);
        $agent = $bridge->resolveOrCreateForOrganization($this->organizationId, $settings);
        $bridge->touchAgent($agent, 'test', true, '');
    }

    protected function device(bool $testMode = false): KraDeviceService
    {
        $device = KraDeviceService::fromSettings([
            'enable_kra_device' => true,
            'kra_device_ip' => 'http://localhost:4000',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'kra_device_test_mode' => $testMode,
            '_organization_id' => $this->organizationId,
        ], $this->organizationId);

        return $device;
    }

    public function test_signed_live_invoice_queues_manual_etims_upload_when_not_yet_transmitted(): void
    {
        config(['testing.kra_agent_command_handler' => function (string $path, array $body): array {
            if ($path === '/api/complete-workflow') {
                return ['success' => true, 'body' => [
                    'success' => true,
                    'message' => 'Signature generated',
                    'transmit_status' => 'Signed invoice has not yet been transmitted to eTIMS. Please transmit.',
                ]];
            }

            return ['success' => true, 'body' => [
                'Success' => true,
                'message' => 'Invoices queued for manual upload to ETIMS successfully',
            ]];
        }]);

        $result = $this->device()->sendSale(
            [['product_name' => 'Item', 'quantity' => 1, 'amount' => 10, 'product_vat' => 0]],
            10,
            '100000001',
        );

        $this->assertTrue($result['success']);
        $this->assertSame('queued', $result['response']['online_upload_status']);
        $this->assertSame('Invoices queued for manual upload to eTIMS.', $result['response']['transmit_status']);
        $credit = $this->device()->sendCreditNote(
            [['product_name' => 'Item', 'quantity' => 1, 'amount' => 10, 'product_vat' => 0]],
            10,
            '100000004',
            '82729',
            '06',
            'MPESA',
        );
        $this->assertTrue($credit['success']);
        $this->assertSame('queued', $credit['response']['online_upload_status']);
        $upload = \App\Models\KraAgentCommand::query()
            ->where('path', '/api/invoices/manually-upload/DEJA02220240050')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertNull($upload->body_json);
        $this->assertSame(2, \App\Models\KraAgentCommand::query()
            ->where('path', '/api/invoices/manually-upload/DEJA02220240050')
            ->count());
    }

    public function test_checkout_defers_manual_etims_upload_so_till_gets_qr_immediately(): void
    {
        config(['testing.kra_agent_command_handler' => function (string $path): array {
            if ($path === '/api/complete-workflow') {
                return ['success' => true, 'body' => [
                    'success' => true,
                    'message' => 'Signature generated',
                    'transmit_status' => 'Signed invoice has not yet been transmitted to eTIMS. Please transmit.',
                    'invoice_number' => 'CU-CHECKOUT-FAST',
                    'signature' => 'SIG-CHECKOUT',
                ]];
            }

            $this->fail('Checkout must not call manually-upload synchronously: '.$path);
        }]);

        $result = $this->device()->sendSale(
            [['product_name' => 'Item', 'quantity' => 1, 'amount' => 10, 'product_vat' => 0]],
            10,
            '100000010',
            null,
            ['checkout' => true],
        );

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['response']['online_upload_status']);
        $this->assertTrue($result['response']['online_upload_deferred'] ?? false);
        $this->assertDatabaseMissing('kra_agent_commands', [
            'path' => '/api/invoices/manually-upload/DEJA02220240050',
        ]);
    }

    public function test_already_transmitted_invoice_does_not_send_manual_upload(): void
    {
        config(['testing.kra_agent_command_handler' => fn () => ['success' => true, 'body' => [
            'success' => true,
            'message' => 'Signature generated',
            'transmit_status' => 'Invoice has been transmitted to eTIMS.',
        ]]]);

        $result = $this->device()->sendSale(
            [['product_name' => 'Item', 'quantity' => 1, 'amount' => 10, 'product_vat' => 0]],
            10,
            '100000002',
        );

        $this->assertTrue($result['success']);
        $this->assertSame('transmitted', $result['response']['online_upload_status']);
        $this->assertDatabaseMissing('kra_agent_commands', [
            'path' => '/api/invoices/manually-upload/DEJA02220240050',
        ]);
    }

    public function test_test_mode_never_queues_online_etims_upload(): void
    {
        config(['testing.kra_agent_command_handler' => fn () => ['success' => true, 'body' => [
            'success' => true,
            'message' => 'Test signature generated',
            'transmit_status' => 'Signed invoice has not yet been transmitted to eTIMS. Please transmit.',
        ]]]);

        $result = $this->device(testMode: true)->sendSale(
            [['product_name' => 'Item', 'quantity' => 1, 'amount' => 10, 'product_vat' => 0]],
            10,
            '100000003',
        );

        $this->assertTrue($result['success']);
        $this->assertDatabaseMissing('kra_agent_commands', [
            'path' => '/api/invoices/manually-upload/DEJA02220240050',
        ]);
    }

    public function test_scheduled_retry_queues_pending_live_invoice_for_etims_upload(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $organization = Organization::query()->findOrFail($this->organizationId);
        $settings = $organization->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_kra_device' => true,
            'enable_kra_agent' => true,
            'kra_device_ip' => 'http://localhost:4000',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
            'kra_comstore_api_key' => 'scheduled-upload-test-key',
            'kra_device_test_mode' => false,
        ]);
        $organization->update(['module_settings' => $settings]);

        $sale = Sale::create([
            'order_num' => 93001,
            'branch_id' => $user->branch_id,
            'organization_id' => $this->organizationId,
            'channel' => 'pos',
            'cashier_id' => $user->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 10,
            'total_vat' => 0,
        ]);
        $response = KraResponse::create([
            'sale_id' => $sale->id,
            'organization_id' => $this->organizationId,
            'order_no' => 93001,
            'invoice_number' => 'CU-SCHEDULED-93001',
            'status' => 'success',
            'request_payload' => [
                'is_test' => false,
                'sign_structure' => ['SignType' => '1', 'InvoiceType' => 'original'],
            ],
            'response_payload' => [
                'document_type' => 'sale',
                'online_upload_status' => 'pending',
            ],
        ]);

        config(['testing.kra_agent_command_handler' => function (string $path): array {
            if (str_starts_with($path, '/api/invoices/manually-upload/')) {
                return [
                    'success' => true,
                    'body' => [
                        'success' => true,
                        'message' => 'Invoices queued for manual upload to eTIMS.',
                    ],
                ];
            }

            // Soft-fail fiscalization retries may call complete-workflow in the same command.
            return [
                'success' => true,
                'body' => [
                    'success' => true,
                    'message' => 'Signature generated',
                    'transmit_status' => 'Invoice has been transmitted to eTIMS.',
                    'invoice_number' => 'CU-RETRY',
                    'signature' => 'SIG-RETRY',
                ],
            ];
        }]);

        $this->artisan('erp:retry-pending-kra-invoice-uploads', [
            '--organization_id' => $this->organizationId,
        ])->assertSuccessful();

        $fresh = $response->fresh();
        $this->assertSame('queued', $fresh->response_payload['online_upload_status'] ?? null);
        $this->assertSame(
            'Invoices queued for manual upload to eTIMS.',
            $fresh->response_payload['online_upload_response']['message'] ?? null,
        );
        $this->assertGreaterThanOrEqual(1, KraAgentCommand::query()
            ->where('path', 'like', '/api/invoices/manually-upload/%')
            ->count());
    }
}