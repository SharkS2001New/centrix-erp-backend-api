<?php

namespace Tests\Feature;

use App\Models\Organization;
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
}