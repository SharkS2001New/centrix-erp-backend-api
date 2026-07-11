<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsappConfig;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
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
        $this->assertSame('whatsapp', $sale->channel);
        $expectedStatus = \App\Services\Erp\OrderWorkflowService::forGate(
            (new \App\Services\Erp\CapabilityGate)->forOrganization($org)
        )->resolveSaveStatus('whatsapp');
        $this->assertSame($expectedStatus, $sale->status);
        $this->assertGreaterThan(0, $sale->items()->count());
        $this->assertSame(1, (int) $sale->items()->first()->on_wholesale_retail);
    }

    public function test_sales_index_lists_whatsapp_orders_by_order_source_filter(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $admin->organization_id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );
        Sanctum::actingAs($admin);

        $customer = Customer::query()
            ->where('organization_id', $admin->organization_id)
            ->where('phone_number', '0722111222')
            ->firstOrFail();

        $sale = Sale::query()->create([
            'order_num' => 991001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'whatsapp',
            'order_source' => 'whatsapp',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'booked',
            'payment_status' => 'unpaid',
            'order_total' => 100,
            'total_vat' => 0,
            'amount_paid' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $today = now()->toDateString();
        $ids = collect(
            $this->getJson("/api/v1/sales?order_source=whatsapp&from_date={$today}&to_date={$today}&per_page=100")
                ->assertOk()
                ->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($sale->id));
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

    public function test_unknown_whatsapp_number_can_self_register(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $this->enableWhatsappForOrganization($org, $admin);

        $newPhone = '254733998877';
        $this->assertNull(
            \App\Support\PhoneNumber::normalize($newPhone)
                ? app(\App\Services\Customers\CustomerPhoneLookup::class)->findByPhone($org->id, $newPhone)
                : null
        );

        $this->postSignedWebhook('wamid.r01', 'HI', $newPhone);
        $this->postSignedWebhook('wamid.r02', '1', $newPhone); // register
        $this->postSignedWebhook('wamid.r03', 'WhatsApp Test Shop', $newPhone);
        $this->postSignedWebhook('wamid.r04', 'Nairobi', $newPhone);
        $this->postSignedWebhook('wamid.r05', '1', $newPhone); // pick route (distribution org)
        $this->postSignedWebhook('wamid.r06', 'SKIP', $newPhone); // KRA optional
        $this->postSignedWebhook('wamid.r07', 'SKIP', $newPhone); // shop photo optional
        $this->postSignedWebhook('wamid.r08', 'SKIP', $newPhone)->assertOk(); // location optional

        $customer = app(\App\Services\Customers\CustomerPhoneLookup::class)->findByPhone($org->id, $newPhone);
        $this->assertNotNull($customer);
        $this->assertSame('WhatsApp Test Shop', $customer->customer_name);
        $this->assertSame('Nairobi', $customer->town);
        $this->assertNotNull($customer->route_id);
        $this->assertNull($customer->kra_pin);
        $this->assertNull($customer->latitude);
        $this->assertNull($customer->longitude);
        $this->assertNull($customer->shop_image);

        $welcome = (string) \App\Models\WhatsappMessageLog::query()
            ->where('organization_id', $org->id)
            ->where('direction', 'out')
            ->latest('id')
            ->value('body');
        $this->assertStringContainsString('Registered successfully', $welcome);
        $this->assertStringContainsString('Place new order', $welcome);
    }

    public function test_whatsapp_registration_saves_kra_and_confirmed_shop_location(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $this->enableWhatsappForOrganization($org, $admin);

        $newPhone = '254733445566';

        $this->postSignedWebhook('wamid.loc01', 'HI', $newPhone);
        $this->postSignedWebhook('wamid.loc02', '1', $newPhone);
        $this->postSignedWebhook('wamid.loc03', 'Location Shop', $newPhone);
        $this->postSignedWebhook('wamid.loc04', 'Kisumu', $newPhone);
        $this->postSignedWebhook('wamid.loc05', '1', $newPhone);
        $this->postSignedWebhook('wamid.loc06', 'A123456789Z', $newPhone); // KRA
        $this->postSignedWebhook('wamid.loc07', 'SKIP', $newPhone); // photo
        $this->postSignedLocationWebhook('wamid.loc08', -1.2921, 36.8219, $newPhone);

        $confirmPrompt = (string) \App\Models\WhatsappMessageLog::query()
            ->where('organization_id', $org->id)
            ->where('direction', 'out')
            ->latest('id')
            ->value('body');
        $this->assertStringContainsString('Is this the location of your shop', $confirmPrompt);

        $this->postSignedWebhook('wamid.loc09', 'YES', $newPhone)->assertOk();

        $customer = app(\App\Services\Customers\CustomerPhoneLookup::class)->findByPhone($org->id, $newPhone);
        $this->assertNotNull($customer);
        $this->assertSame('A123456789Z', $customer->kra_pin);
        $this->assertEqualsWithDelta(-1.2921, (float) $customer->latitude, 0.0001);
        $this->assertEqualsWithDelta(36.8219, (float) $customer->longitude, 0.0001);
    }

    public function test_greeting_introduces_org_agent_name(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $this->enableWhatsappForOrganization($org, $admin);

        $this->postSignedWebhook('wamid.hi01', 'Hello')->assertOk();

        $body = (string) \App\Models\WhatsappMessageLog::query()
            ->where('organization_id', $org->id)
            ->where('direction', 'out')
            ->latest('id')
            ->value('body');

        $this->assertStringContainsString('My name is *Omega*', $body);
        $this->assertStringContainsString('I am a powered WhatsApp Agent from CentrixERP', $body);
        $this->assertStringContainsString('Place new order', $body);
    }

    public function test_unknown_customer_help_includes_office_phone(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $this->enableWhatsappForOrganization($org, $admin);

        $this->postSignedWebhook('wamid.h01', 'HI', '254700111222')->assertOk();

        $body = (string) \App\Models\WhatsappMessageLog::query()
            ->where('organization_id', $org->id)
            ->where('direction', 'out')
            ->latest('id')
            ->value('body');

        $this->assertStringContainsString('Register your shop', $body);
        $this->assertStringContainsString('0700111222', $body);
    }

    public function test_platform_live_simulate_confirm_creates_real_order(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $this->enableWhatsappForOrganization($org, $admin);

        $customer = Customer::query()
            ->where('organization_id', $org->id)
            ->where('phone_number', '0722111222')
            ->firstOrFail();

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $sessionId = null;
        foreach (['HI', '1', '1', 'R', '1', '2', 'CONFIRM'] as $message) {
            $response = $this->postJson('/api/v1/admin/whatsapp/preview/simulate', [
                'organization_id' => $org->id,
                'message' => $message,
                'customer_num' => (string) $customer->customer_num,
                'phone' => '254722111222',
                'session_id' => $sessionId,
                'place_real_orders' => true,
                'bot_user_id' => $admin->id,
            ])->assertOk();

            $sessionId = $response->json('session.session_id');
        }

        $this->assertNotEmpty($response->json('placed_order.order_num'));
        $this->assertFalse((bool) $response->json('dry_run'));
        $this->assertStringContainsString('Live test order placed', (string) $response->json('reply'));

        $sale = Sale::query()
            ->where('organization_id', $org->id)
            ->where('order_source', 'whatsapp')
            ->where('customer_num', $customer->customer_num)
            ->latest('id')
            ->first();

        $this->assertNotNull($sale);
        $this->assertSame((int) $response->json('placed_order.order_num'), (int) $sale->order_num);
    }

    public function test_confirm_from_review_completes_in_dry_run(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $this->enableWhatsappForOrganization($org, $admin);

        $customer = Customer::query()
            ->where('organization_id', $org->id)
            ->where('phone_number', '0722111222')
            ->firstOrFail();

        $handler = app(\App\Services\WhatsApp\WhatsAppBotHandler::class);
        $config = app(\App\Services\WhatsApp\WhatsAppConfigResolver::class)
            ->resolveForOrganizationPreview($org, null, $admin);
        $this->assertNotNull($config);

        $session = null;
        foreach (['HI', '1', '1', 'R', '1', '2', 'CONFIRM'] as $message) {
            $result = $handler->simulate($config, '254722111222', $message, $customer, $session, false);
            $session = [
                'state' => $result['state'],
                'payload' => $result['payload'],
                'customer_num' => $result['customer_num'],
                'phone' => $result['phone'],
            ];
        }

        $this->assertContains('place_order', $result['would_mutate']);
        $this->assertStringContainsString('TEST MODE — order not placed', $result['reply']);
        $this->assertSame('main_menu', $result['state']);
    }

    public function test_platform_live_simulate_confirm_creates_real_order_when_whatsapp_channel_disabled(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);

        // Platform gate on, org WhatsApp channel off — production bot would refuse, simulator must still place.
        $settings = $org->module_settings ?? [];
        $settings['whatsapp'] = array_merge($settings['whatsapp'] ?? [], [
            'enable_whatsapp_orders' => true,
            'enabled' => false,
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

        $customer = Customer::query()
            ->where('organization_id', $org->id)
            ->where('phone_number', '0722111222')
            ->firstOrFail();

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $sessionId = null;
        $response = null;
        foreach (['HI', '1', '1', 'R', '1', '2', 'CONFIRM'] as $message) {
            $response = $this->postJson('/api/v1/admin/whatsapp/preview/simulate', [
                'organization_id' => $org->id,
                'message' => $message,
                'customer_num' => (string) $customer->customer_num,
                'phone' => '254722111222',
                'session_id' => $sessionId,
                'place_real_orders' => true,
                'bot_user_id' => $admin->id,
            ])->assertOk();

            $sessionId = $response->json('session.session_id');
        }

        $this->assertNotEmpty($response->json('placed_order.order_num'));
        $this->assertStringContainsString('Live test order placed', (string) $response->json('reply'));
        $this->assertStringNotContainsString('Channel [whatsapp] is not enabled', (string) $response->json('reply'));

        $sale = Sale::query()
            ->where('organization_id', $org->id)
            ->where('order_source', 'whatsapp')
            ->where('customer_num', $customer->customer_num)
            ->latest('id')
            ->first();

        $this->assertNotNull($sale);
        $this->assertSame('whatsapp', $sale->channel);
        $this->assertSame('whatsapp', $sale->order_source);
    }

    public function test_repeat_last_order_loads_customer_sales_in_simulator(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $this->enableWhatsappForOrganization($org, $admin);

        $customer = Customer::query()
            ->where('organization_id', $org->id)
            ->where('phone_number', '0722111222')
            ->firstOrFail();

        // Seed a normal backoffice sale for this customer (what "Repeat last order" should find).
        $existing = Sale::query()
            ->where('organization_id', $org->id)
            ->where('customer_num', $customer->customer_num)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->latest('id')
            ->first();

        if (! $existing) {
            $this->postSignedWebhook('wamid.rep01', 'HI');
            $this->postSignedWebhook('wamid.rep02', '1');
            $this->postSignedWebhook('wamid.rep03', '1');
            $this->postSignedWebhook('wamid.rep04', 'R');
            $this->postSignedWebhook('wamid.rep05', '1');
            $this->postSignedWebhook('wamid.rep06', '2');
            $this->postSignedWebhook('wamid.rep07', 'CONFIRM')->assertOk();
        }

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $hi = $this->postJson('/api/v1/admin/whatsapp/preview/simulate', [
            'organization_id' => $org->id,
            'message' => 'HI',
            'customer_num' => (string) $customer->customer_num,
            'phone' => '254722111222',
            'place_real_orders' => false,
        ])->assertOk();

        $sessionId = $hi->json('session.session_id');

        $repeat = $this->postJson('/api/v1/admin/whatsapp/preview/simulate', [
            'organization_id' => $org->id,
            'message' => '2',
            'customer_num' => (string) $customer->customer_num,
            'phone' => '254722111222',
            'session_id' => $sessionId,
            'place_real_orders' => false,
        ])->assertOk();

        $this->assertSame('repeat_confirm', $repeat->json('state'));
        $this->assertStringContainsString('Your last order', (string) $repeat->json('reply'));
        $this->assertStringNotContainsString('No previous order found', (string) $repeat->json('reply'));
        $this->assertNotEmpty($repeat->json('cart'));
    }

    protected function enableWhatsappForOrganization(Organization $org, User $admin): void
    {
        $settings = $org->module_settings ?? [];
        $settings['whatsapp'] = array_merge($settings['whatsapp'] ?? [], [
            'enable_whatsapp_orders' => true,
            'enabled' => true,
            'agent_name' => 'Omega',
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

    protected function postSignedWebhook(string $messageId, string $text, ?string $fromPhone = null)
    {
        return $this->postSignedInboundMessage($messageId, [
            'from' => $fromPhone ?? self::CUSTOMER_PHONE,
            'id' => $messageId,
            'type' => 'text',
            'text' => ['body' => $text],
        ], $fromPhone);
    }

    protected function postSignedLocationWebhook(
        string $messageId,
        float $latitude,
        float $longitude,
        ?string $fromPhone = null,
    ) {
        return $this->postSignedInboundMessage($messageId, [
            'from' => $fromPhone ?? self::CUSTOMER_PHONE,
            'id' => $messageId,
            'type' => 'location',
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
        ], $fromPhone);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function postSignedInboundMessage(string $messageId, array $message, ?string $fromPhone = null)
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
                                    $message,
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
