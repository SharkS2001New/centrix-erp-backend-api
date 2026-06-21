<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrganizationSecuritySettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_org_admin_can_update_per_organization_security_settings(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/erp/settings/security', [
            'screen_lock_minutes' => 5,
            'session_idle_minutes' => 60,
        ])
            ->assertOk()
            ->assertJsonPath('security.screen_lock_minutes', 5)
            ->assertJsonPath('security.session_idle_minutes', 60);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('screen_lock_minutes', 5)
            ->assertJsonPath('session_idle_minutes', 60);

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $this->assertSame(5, $org->fresh()->module_settings['security']['screen_lock_minutes']);
        $this->assertSame(60, $org->fresh()->module_settings['security']['session_idle_minutes']);
    }

    public function test_screen_lock_must_be_less_than_sign_out_timeout(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/erp/settings/security', [
            'screen_lock_minutes' => 30,
            'session_idle_minutes' => 20,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['screen_lock_minutes']);
    }
}
