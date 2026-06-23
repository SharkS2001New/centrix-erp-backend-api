<?php

namespace Tests\Feature;

use App\Models\AiKnowledgeEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformAiTrainingTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected Organization $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'is_admin' => true,
        ]);

        $this->tenant = Organization::factory()->create([
            'company_code' => 'DEMO01',
            'module_settings' => [
                'ai' => [
                    'enabled' => true,
                    'api_key' => 'sk-test-org-key-123456',
                    'model' => 'gpt-4o-mini',
                ],
            ],
        ]);
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

    public function test_training_chat_uses_preview_org_and_platform_knowledge(): void
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
            ->postJson('/api/v1/admin/ai-training/chat', [
                'preview_organization_id' => $this->tenant->id,
                'workspace_id' => 'backoffice',
                'pathname' => '/purchases',
                'message' => 'Where do I record a GRN?',
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
}
