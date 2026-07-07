<?php


/**
 * Local-only tests — excluded from CI / default `composer test`.
 * Run manually: composer test:local
 */

namespace Tests\Local\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->org = Organization::findOrFail($this->user->organization_id);
        Sanctum::actingAs($this->user);
    }

    public function test_ai_disabled_by_default_for_organization(): void
    {
        $this->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('organization_enabled', false)
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('api_key_set', false);
    }

    public function test_org_must_store_its_own_api_key(): void
    {
        $this->patchJson('/api/v1/erp/settings/ai', [
            'enabled' => true,
            'api_key' => 'sk-test-org-key-123456',
            'model' => 'gpt-4o-mini',
        ])->assertOk()
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('available', true)
            ->assertJsonPath('settings.api_key_set', true);

        $stored = Organization::findOrFail($this->org->id)->module_settings['ai']['api_key'] ?? '';
        $this->assertSame('sk-test-org-key-123456', $stored);

        $this->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('organization_enabled', true)
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('api_key_set', true);
    }

    public function test_enabled_without_org_key_is_not_available(): void
    {
        $this->patchJson('/api/v1/erp/settings/ai', [
            'enabled' => true,
        ])->assertOk()
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('available', false);

        $this->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('enabled', false);
    }
}
