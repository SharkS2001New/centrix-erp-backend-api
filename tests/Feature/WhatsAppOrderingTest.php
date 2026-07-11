<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappConfig;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class WhatsAppOrderingTest extends TestCase
{
    use RefreshesErpDatabase;

    private const PHONE_NUMBER_ID = 'test-wa-phone-id-001';

    private const APP_SECRET = 'whatsapp-test-app-secret';

    private const CUSTOMER_PHONE = '254722111222';

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.app_secret' => self::APP_SECRET]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
        ]);
    }

    public function test_webhook_rejects_invalid_signature_when_app_secret_configured(): void
    {
        $this->postJson('/api/v1/webhooks/whatsapp', [
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ], [
            'X-Hub-Signature-256' => 'sha256=deadbeef',
        ])->assertForbidden();
    }

    public function test_inbound_webhook_places_whatsapp_order_end_to_end(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $this->enableWhatsappForOrganization($org, $admin);

        $customer = Customer::query()
            ->where('organization_id', $org->id)
            ->where('phone_number', '0722111222')
            ->firstOrFail();

        $this->assertNotNull($customer->route_id);

        $this->postSignedWebhook('wamid.001', 'HI');
        $this->postSignedWebhook('wamid.002', '1'); // browse
        $this->postSignedWebhook('wamid.003', '1'); // pick first product (asks R/W when sell_on_retail)
        $this->postSignedWebhook('wamid.004', 'R'); // retail → shop stock
        $this->postSignedWebhook('wamid.005', '1'); // qty
        $this->postSignedWebhook('wamid.006', '2'); // review
        $this->postSignedWebhook('wamid.007', 'CONFIRM')->assertOk();

        $sale = Sale::query()
            ->where('organization_id', $org->id)
            ->where('order_source', 'whatsapp')
            ->where('customer_num', $customer->customer_num)
            ->latest('id')
            ->first();

        $this->assertNotNull($sale, 'Expected a WhatsApp sale to be created.');
        $this->assertSame('whatsapp', $sale->order_source);
        $this->assertSame('backend', $sale->channel);
        $this->assertGreaterThan(0, $sale->items()->count());
        $this->assertSame(1, (int) $sale->items()->first()->on_wholesale_retail);
    }

    public function test_whatsapp_order_summary_includes_unit_price_and_rw(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $this->enableWhatsappForOrganization($org, $admin);

        $this->postSignedWebhook('wamid.s01', 'HI');
        $this->postSignedWebhook('wamid.s02', '1');
        $this->postSignedWebhook('wamid.s03', '1');
        $this->postSignedWebhook('wamid.s04', 'R');
        $this->postSignedWebhook('wamid.s05', '2');
        $this->postSignedWebhook('wamid.s06', '2')->assertOk();

        $body = (string) \App\Models\WhatsappMessageLog::query()
            ->where('organization_id', $org->id)
            ->where('direction', 'out')
            ->latest('id')
            ->value('body');

        $this->assertStringContainsString('Order summary', $body);
        $this->assertMatchesRegularExpression('/,\s*KES\s*[\d,]+,\s*.+,\s*KES\s*[\d,]+,\s*R\b/', $body);
    }

    protected function enableWhatsappForOrganization(Organization $org, User $admin): void
    {
        $settings = $org->module_settings ?? [];
        $settings['whatsapp'] = array_merge($settings['whatsapp'] ?? [], [
            'enable_whatsapp_orders' => true,
            'enabled' => true,
        ]);
        $org->update(['module_settings' => $settings]);

        WhatsappConfig::query()->updateOrCreate(
            ['organization_id' => $org->id],
            [
                'branch_id' => $admin->branch_id,
                'bot_user_id' => $admin->id,
                'phone_number_id' => self::PHONE_NUMBER_ID,
                'access_token' => 'EAA-test-token',
                'display_phone' => '+254700000099',
                'is_active' => true,
            ],
        );
    }

    protected function postSignedWebhook(string $messageId, string $text)
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => self::PHONE_NUMBER_ID,
                                ],
                                'messages' => [
                                    [
                                        'from' => self::CUSTOMER_PHONE,
                                        'id' => $messageId,
                                        'type' => 'text',
                                        'text' => ['body' => $text],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET);

        return $this->call(
            'POST',
            '/api/v1/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Hub-Signature-256' => $signature,
            ],
            $body,
        );
    }
}
