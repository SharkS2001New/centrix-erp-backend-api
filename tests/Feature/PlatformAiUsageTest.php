<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformAiUsageTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $superAdmin;

    protected User $tenantAdmin;

    protected Organization $orgA;

    protected Organization $orgB;

    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('ai_usage_logs')) {
            $this->markTestSkipped('ai_usage_logs table missing');
        }

        $this->superAdmin = User::query()->where('username', 'superadmin')->firstOrFail();
        $this->tenantAdmin = User::query()->where('username', 'admin')->firstOrFail();
        $this->orgA = Organization::query()->findOrFail($this->tenantAdmin->organization_id);
        $orgB = Organization::query()
            ->where('id', '!=', $this->orgA->id)
            ->where('id', '!=', $this->superAdmin->organization_id)
            ->first();

        $this->orgB = $orgB ?? Organization::query()->create([
            'company_code' => 'AIUSG'.substr(uniqid(), -4),
            'org_name' => 'AI Usage Tenant B',
            'org_email' => 'aiusg@example.com',
            'primary_tel' => '0700000000',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'is_active' => true,
        ]);

        $this->userB = User::query()->create([
            'organization_id' => $this->orgB->id,
            'role_id' => $this->tenantAdmin->role_id,
            'username' => 'ai_usage_bob_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Bob Buyer',
            'access_scope' => 'org',
            'is_admin' => false,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
    }

    public function test_usage_summary_groups_by_org_and_user(): void
    {
        $this->seedLogs();
        Sanctum::actingAs($this->superAdmin);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $response = $this->getJson("/api/v1/admin/ai-training/usage?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('summary.requests', 4)
            ->assertJsonPath('summary.error_count', 1);

        $orgs = collect($response->json('by_organization'));
        $this->assertTrue($orgs->contains(fn ($row) => (int) $row['organization_id'] === (int) $this->orgA->id && (int) $row['requests'] === 2));
        $this->assertTrue($orgs->contains(fn ($row) => (int) $row['organization_id'] === (int) $this->orgB->id && (int) $row['requests'] === 2));

        $users = collect($response->json('by_user'));
        $this->assertTrue($users->contains(fn ($row) => (int) $row['user_id'] === (int) $this->tenantAdmin->id && (int) $row['requests'] === 2));
        $this->assertGreaterThan(0, (float) $response->json('summary.estimated_cost'));
    }

    public function test_usage_events_lists_recent_rows(): void
    {
        $this->seedLogs();
        Sanctum::actingAs($this->superAdmin);

        $this->getJson('/api/v1/admin/ai-training/usage/events?organization_id='.$this->orgA->id.'&per_page=10')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.organization_id', $this->orgA->id);
    }

    public function test_non_super_admin_cannot_view_usage(): void
    {
        Sanctum::actingAs($this->tenantAdmin);

        $this->getJson('/api/v1/admin/ai-training/usage')
            ->assertForbidden();
    }

    protected function seedLogs(): void
    {
        AiUsageLog::query()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->tenantAdmin->id,
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'input_tokens' => 1000,
            'output_tokens' => 200,
            'total_tokens' => 1200,
            'estimated_cost' => 0,
            'status' => 'ok',
            'tools_used' => ['get_sales_summary'],
            'latency_ms' => 120,
            'prompt_preview' => 'Where is front desk check-in?',
        ]);
        AiUsageLog::query()->create([
            'organization_id' => $this->orgA->id,
            'user_id' => $this->tenantAdmin->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'input_tokens' => 500,
            'output_tokens' => 100,
            'total_tokens' => 600,
            'estimated_cost' => 0,
            'status' => 'success',
            'tools_used' => ['get_stock_summary'],
            'latency_ms' => 90,
            'prompt_preview' => 'Where is front desk check in?',
        ]);
        AiUsageLog::query()->create([
            'organization_id' => $this->orgB->id,
            'user_id' => $this->userB->id,
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'input_tokens' => 800,
            'output_tokens' => 150,
            'total_tokens' => 950,
            'estimated_cost' => 0,
            'status' => 'ok',
            'latency_ms' => 140,
            'prompt_preview' => 'Sales today?',
        ]);
        AiUsageLog::query()->create([
            'organization_id' => $this->orgB->id,
            'user_id' => $this->userB->id,
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'input_tokens' => 50,
            'output_tokens' => 10,
            'total_tokens' => 60,
            'estimated_cost' => 0,
            'status' => 'error',
            'error_code' => 'rate_limited',
            'error_message' => 'Too many requests',
            'latency_ms' => 40,
            'prompt_preview' => 'Sales today?',
        ]);
    }

    public function test_usage_summary_includes_common_questions(): void
    {
        $this->seedLogs();
        Sanctum::actingAs($this->superAdmin);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $response = $this->getJson("/api/v1/admin/ai-training/usage?from={$from}&to={$to}")
            ->assertOk();

        $questions = collect($response->json('common_questions'));
        $this->assertTrue($questions->isNotEmpty());
        $this->assertTrue(
            $questions->contains(fn ($row) => (int) $row['count'] >= 2 && str_contains(strtolower($row['question']), 'front desk')),
        );
    }
}
