<?php

/**
 * Local-only tests — excluded from CI / default `composer test`.
 * Run manually: composer test:local
 */

namespace Tests\Local\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiInsightDeliveryService;
use App\Services\Ai\AiInsightScheduler;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiInsightsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->org = Organization::findOrFail($this->user->organization_id);
        Sanctum::actingAs($this->user);
    }

    protected function enableAiInsights(): void
    {
        $settings = $this->org->module_settings ?? [];
        $settings['ai'] = [
            'enabled' => true,
            'provider' => 'openai',
            'api_key' => 'sk-test-org-key-123456',
            'model' => 'gpt-4o-mini',
            'base_url' => '',
            'insights' => [
                'enabled' => true,
                'channels' => ['email' => true, 'whatsapp' => false, 'sms' => false],
                'recipients' => [
                    'emails' => ['ops@example.com'],
                    'phones' => [],
                    'whatsapp_phones' => [],
                ],
                'stock_pulse' => [
                    'enabled' => false,
                    'schedule_time' => '07:00',
                    'lookback_days' => 14,
                ],
                'sales_brief' => [
                    'enabled' => false,
                    'schedule_time' => '07:00',
                    'lookback_days' => 7,
                ],
                'exception_alerts' => [
                    'enabled' => false,
                    'low_stock' => true,
                    'unpaid_spike' => false,
                ],
            ],
        ];
        $this->org->update(['module_settings' => $settings]);
        $this->org->refresh();
    }

    public function test_ai_settings_patch_accepts_insights(): void
    {
        $this->patchJson('/api/v1/erp/settings/ai', [
            'enabled' => true,
            'api_key' => 'sk-test-org-key-123456',
            'model' => 'gpt-4o-mini',
            'insights' => [
                'enabled' => true,
                'channels' => ['email' => true, 'whatsapp' => true, 'sms' => false],
                'recipients' => ['emails' => ['ops@example.com']],
                'stock_pulse' => ['enabled' => true, 'schedule_time' => '07:15'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('settings.insights.enabled', true)
            ->assertJsonPath('settings.insights.channels.whatsapp', true)
            ->assertJsonPath('settings.insights.stock_pulse.schedule_time', '07:15');
    }

    public function test_analyze_report_returns_insight_with_mocked_openai(): void
    {
        $this->enableAiInsights();

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Sales are steady.',
                            'findings' => ['Top SKU grew 12%'],
                            'actions' => [
                                ['label' => 'Open daily sales', 'href' => '/reports/daily-sales'],
                            ],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $response = $this->postJson('/api/v1/ai/insights/analyze-report', [
            'report_key' => 'daily-sales',
            'filters' => ['from' => '2026-07-01', 'to' => '2026-07-31'],
            'rows' => [
                ['sale_date' => '2026-07-30', 'order_total' => 1200],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('summary', 'Sales are steady.')
            ->assertJsonPath('findings.0', 'Top SKU grew 12%')
            ->assertJsonStructure(['insight_id', 'actions', 'raw_markdown']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/chat/completions'));
    }

    public function test_stock_pulse_endpoint_with_mocked_openai(): void
    {
        $this->enableAiInsights();

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Several SKUs need reorder.',
                            'findings' => ['Item A below reorder'],
                            'actions' => [
                                ['label' => 'Low stock report', 'href' => '/reports/low-stock'],
                            ],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->postJson('/api/v1/ai/insights/stock-pulse', [])
            ->assertOk()
            ->assertJsonPath('type', 'stock_pulse')
            ->assertJsonPath('summary', 'Several SKUs need reorder.');
    }

    public function test_deliver_skips_gracefully_when_mail_not_configured(): void
    {
        $this->enableAiInsights();

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Brief body',
                            'findings' => [],
                            'actions' => [],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $insightId = $this->postJson('/api/v1/ai/insights/sales-brief', [])
            ->assertOk()
            ->json('insight_id');

        $response = $this->postJson('/api/v1/ai/insights/deliver', [
            'insight_id' => $insightId,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['sent', 'skipped', 'errors']);
        $this->assertIsArray($response->json('sent'));
    }

    public function test_dashboard_cards_endpoint(): void
    {
        $this->enableAiInsights();

        $this->getJson('/api/v1/ai/insights/dashboard')
            ->assertOk()
            ->assertJsonStructure(['cards']);
    }

    public function test_scheduler_run_due_without_matching_time_is_noop(): void
    {
        $this->enableAiInsights();

        $stats = app(AiInsightScheduler::class)->runDue('23:59');

        $this->assertArrayHasKey('orgs_checked', $stats);
        $this->assertSame(0, $stats['stock_pulse']);
        $this->assertSame(0, $stats['sales_brief']);
    }

    public function test_delivery_service_skips_when_no_recipients(): void
    {
        $org = $this->org->fresh();
        $result = app(AiInsightDeliveryService::class)->deliver($org, [
            'type' => 'stock_pulse',
            'summary' => 'Test',
            'findings' => [],
            'raw_markdown' => 'Test',
        ], [
            'emails' => [],
            'phones' => [],
            'whatsapp_phones' => [],
        ]);

        $this->assertSame(0, $result['sent']['email']);
        $this->assertNotEmpty($result['skipped']);
    }
}
