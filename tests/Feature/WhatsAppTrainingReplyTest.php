<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappBotTrainingReply;
use App\Services\WhatsApp\WhatsAppTrainingReplyMatcher;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class WhatsAppTrainingReplyTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_crud_training_replies_and_preview_match(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/whatsapp/training', [
            'title' => 'Opening hours',
            'keywords' => 'hours, open, closing time',
            'response_text' => "We are open Mon–Sat 8am–6pm.\nReply *MENU* to order.",
            'match_mode' => 'any',
            'priority' => 200,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('title', 'Opening hours');

        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);

        $this->getJson('/api/v1/admin/whatsapp/training')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->postJson('/api/v1/admin/whatsapp/training/preview', [
            'message' => 'What time do you open today?',
        ])->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('reply', "We are open Mon–Sat 8am–6pm.\nReply *MENU* to order.");

        $this->patchJson("/api/v1/admin/whatsapp/training/{$id}", [
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('is_active', false);

        $this->postJson('/api/v1/admin/whatsapp/training/preview', [
            'message' => 'What time do you open today?',
        ])->assertOk()
            ->assertJsonPath('matched', false);

        $this->deleteJson("/api/v1/admin/whatsapp/training/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('whatsapp_bot_training_replies', ['id' => $id]);
    }

    public function test_matcher_prefers_higher_priority_and_all_mode(): void
    {
        WhatsappBotTrainingReply::query()->create([
            'title' => 'Generic open',
            'keywords' => ['open'],
            'response_text' => 'Generic open reply',
            'match_mode' => 'any',
            'priority' => 10,
            'is_active' => true,
        ]);

        WhatsappBotTrainingReply::query()->create([
            'title' => 'Specific hours',
            'keywords' => ['open', 'hours'],
            'response_text' => 'Specific hours reply',
            'match_mode' => 'all',
            'priority' => 50,
            'is_active' => true,
        ]);

        $matcher = app(WhatsAppTrainingReplyMatcher::class);

        $both = $matcher->match('What are your open hours?');
        $this->assertNotNull($both);
        $this->assertSame('Specific hours reply', $both['response_text']);

        $onlyOpen = $matcher->match('Are you open?');
        $this->assertNotNull($onlyOpen);
        $this->assertSame('Generic open reply', $onlyOpen['response_text']);
    }
}
