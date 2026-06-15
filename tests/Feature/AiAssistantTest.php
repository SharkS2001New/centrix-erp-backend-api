<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_ai_status_when_org_disabled(): void
    {
        config(['ai.platform_enabled' => false]);

        $this->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('organization_enabled', false)
            ->assertJsonPath('enabled', false);
    }

    public function test_ai_chat_when_org_disabled_returns_helpful_message(): void
    {
        $this->postJson('/api/v1/ai/chat', [
            'context' => 'products',
            'message' => 'Which products are low on stock?',
        ])
            ->assertOk()
            ->assertJsonStructure(['reply', 'tools_used']);
    }
}
