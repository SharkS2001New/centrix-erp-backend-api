<?php

namespace Tests\Feature;

use App\Models\AiKnowledgeEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformAiTrainingTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected Organization $platformOrg;

    protected Organization $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'is_admin' => true,
        ]);

        $this->platformOrg = Organization::factory()->create([
            'company_code' => config('erp.platform_company_code', 'PLATFORM'),
            'org_name' => 'Platform Administration',
            'module_settings' => [
                'platform' => true,
                'platform_ai_training' => [
                    'enabled' => true,
                    'api_key' => 'sk-test-platform-key-123456',
                    'model' => 'gpt-4o-mini',
                ],
            ],
        ]);

        $this->tenant = Organization::factory()->create([
            'company_code' => 'DEMO01',
            'module_settings' => [
                'ai' => [
                    'enable_ai' => true,
                    'enabled' => false,
                    'api_key' => '',
                ],
            ],
        ]);
    }

    public function test_super_admin_can_manage_platform_training_settings(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/ai-training/settings')
            ->assertOk()
            ->assertJsonPath('scope', 'platform_training')
            ->assertJsonPath('available', true)
            ->assertJsonPath('settings.api_key_set', true);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson('/api/v1/admin/ai-training/settings', [
                'enabled' => true,
                'api_key' => 'sk-test-platform-key-updated',
                'model' => 'gpt-4o',
            ])
            ->assertOk()
            ->assertJsonPath('settings.api_key_set', true)
            ->assertJsonPath('model', 'gpt-4o');

        $fresh = AiSettingsResolver::forPlatformTraining();
        $this->assertSame('sk-test-platform-key-updated', $fresh['api_key']);
    }

    public function test_super_admin_can_save_platform_gemini_api_key(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson('/api/v1/admin/ai-training/settings', [
                'gemini_api_key' => 'AIza-platform-gemini-test-key',
                'gemini_model' => 'gemini-3.6-flash',
                'free_ai_provider' => 'gemini',
            ])
            ->assertOk()
            ->assertJsonPath('settings.gemini_api_key_set', true)
            ->assertJsonPath('settings.gemini_api_key_hint', '••••-key')
            ->assertJsonPath('gemini_model', 'gemini-3.6-flash');

        $fresh = AiSettingsResolver::forPlatformTraining();
        $this->assertSame('AIza-platform-gemini-test-key', $fresh['gemini_api_key']);
        $this->assertSame('gemini-3.6-flash', $fresh['gemini_model']);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson('/api/v1/admin/ai-training/settings', [
                'gemini_model' => 'gemini-3.7-flash',
            ])
            ->assertOk()
            ->assertJsonPath('settings.gemini_api_key_set', true)
            ->assertJsonPath('settings.gemini_api_key_hint', '••••-key');

        $fresh = AiSettingsResolver::forPlatformTraining();
        $this->assertSame('AIza-platform-gemini-test-key', $fresh['gemini_api_key']);
        $this->assertSame('gemini-3.7-flash', $fresh['gemini_model']);
    }

    public function test_super_admin_can_manage_platform_knowledge(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/ai-training/knowledge', [
                'topic' => 'Returns policy',
                'content' => 'Damaged goods must be returned within 7 days.',
                'workspace_id' => 'backoffice',
            ])
            ->assertCreated()
            ->assertJsonPath('topic', 'Returns policy')
            ->assertJsonPath('scope', 'platform');

        $this->assertDatabaseHas('ai_knowledge_entries', [
            'topic' => 'Returns policy',
            'organization_id' => null,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/ai-training/knowledge')
            ->assertOk()
            ->assertJsonPath('scope', 'platform')
            ->assertJsonCount(1, 'data');

        $entryId = AiKnowledgeEntry::query()->value('id');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->patchJson("/api/v1/admin/ai-training/knowledge/{$entryId}", [
                'content' => 'Damaged goods must be returned within 14 days.',
            ])
            ->assertOk()
            ->assertJsonPath('content', 'Damaged goods must be returned within 14 days.');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/ai-training/knowledge/{$entryId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('ai_knowledge_entries', ['id' => $entryId]);
    }

    public function test_training_chat_uses_platform_credentials_not_tenant_ai_settings(): void
    {
        AiKnowledgeEntry::create([
            'organization_id' => null,
            'source' => 'platform_training',
            'topic' => 'GRN',
            'content' => 'Goods received are recorded via GRN under Purchases.',
            'confirmed' => true,
            'confirmed_at' => now(),
        ]);

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Use Purchases → GRN to record receipts.'],
                ]],
            ]),
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/ai-training/status?preview_organization_id='.$this->tenant->id)
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('chat_ready', true);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/ai-training/chat', [
                'preview_organization_id' => $this->tenant->id,
                'workspace_id' => 'backoffice',
                'pathname' => '/purchases',
                'message' => 'Where do I record a GRN?',
            ])
            ->assertOk()
            ->assertJsonPath('training_mode', true)
            ->assertJsonPath('reply', 'Use Purchases → GRN to record receipts.');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/chat/completions')
                && $request->header('Authorization')[0] === 'Bearer sk-test-platform-key-123456';
        });

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/ai-training/chat', [
                'preview_organization_id' => $this->tenant->id,
                'workspace_id' => 'backoffice',
                'pathname' => '/purchases',
                'message' => 'confirm',
                'confirm_action' => true,
                'pending_action' => ['type' => 'create_product', 'params' => ['product_name' => 'Test']],
            ])
            ->assertOk()
            ->assertJsonPath('training_mode', true)
            ->assertJsonPath('tools_used.0', 'training_mode');
    }

    public function test_non_super_admin_cannot_access_training_api(): void
    {
        $user = User::factory()->create(['organization_id' => $this->tenant->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/ai-training/knowledge')
            ->assertForbidden();
    }

    public function test_super_admin_can_find_merge_and_bulk_delete_duplicate_knowledge(): void
    {
        $first = AiKnowledgeEntry::create([
            'organization_id' => null,
            'source' => 'platform_training',
            'topic' => 'Where is GRN?',
            'content' => 'Open /inventory/receipts.',
            'confirmed' => true,
            'confirmed_at' => now(),
        ]);
        $second = AiKnowledgeEntry::create([
            'organization_id' => null,
            'source' => 'platform_training',
            'topic' => 'Where is GRN',
            'content' => 'Goods received at /inventory/receipts.',
            'confirmed' => true,
            'confirmed_at' => now()->subMinute(),
        ]);
        $third = AiKnowledgeEntry::create([
            'organization_id' => null,
            'source' => 'platform_training',
            'topic' => 'How to add a product',
            'content' => 'Use /inventory/products.',
            'confirmed' => true,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/ai-training/knowledge/duplicates?threshold=85')
            ->assertOk()
            ->assertJsonPath('scope', 'platform')
            ->assertJsonPath('cluster_count', 1)
            ->assertJsonPath('duplicate_entry_count', 1);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/ai-training/knowledge/merge', [
                'keep_id' => $first->id,
                'merge_ids' => [$second->id],
            ])
            ->assertOk()
            ->assertJsonPath('id', $first->id);

        $this->assertDatabaseMissing('ai_knowledge_entries', ['id' => $second->id]);
        $this->assertDatabaseHas('ai_knowledge_entries', ['id' => $first->id]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/ai-training/knowledge/bulk-delete', [
                'entry_ids' => [$third->id],
            ])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertDatabaseMissing('ai_knowledge_entries', ['id' => $third->id]);
    }

    public function test_delete_all_knowledge_requires_confirm_and_wipes_platform_notes(): void
    {
        $keepWorkspace = AiKnowledgeEntry::create([
            'organization_id' => null,
            'source' => 'platform_training',
            'topic' => 'HR leave',
            'content' => 'Open /hr/leave',
            'workspace_id' => 'hr',
            'confirmed' => true,
            'confirmed_at' => now(),
        ]);
        $wipe = AiKnowledgeEntry::create([
            'organization_id' => null,
            'source' => 'platform_training',
            'topic' => 'Where is GRN',
            'content' => 'Open /inventory/receipts',
            'confirmed' => true,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/ai-training/knowledge/delete-all', [])
            ->assertStatus(422);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/ai-training/knowledge/delete-all', [
                'confirm' => true,
                'workspace_id' => 'hr',
            ])
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('scope', 'workspace');

        $this->assertDatabaseMissing('ai_knowledge_entries', ['id' => $keepWorkspace->id]);
        $this->assertDatabaseHas('ai_knowledge_entries', ['id' => $wipe->id]);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/ai-training/knowledge/delete-all', [
                'confirm' => true,
            ])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertDatabaseMissing('ai_knowledge_entries', ['id' => $wipe->id]);
    }

    public function test_training_chat_declines_swahili_questions(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/admin/ai-training/chat', [
                'preview_organization_id' => $this->tenant->id,
                'workspace_id' => 'backoffice',
                'message' => 'nipe mauzo ya leo',
            ])
            ->assertOk()
            ->assertJsonPath('declined_language', true)
            ->assertJsonFragment(['reply' => app(\App\Services\Ai\AiLanguageGuard::class)->englishOnlyMessage()]);

        Http::assertNothingSent();
    }
}
