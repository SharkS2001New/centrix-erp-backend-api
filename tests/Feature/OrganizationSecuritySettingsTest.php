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

    public function test_super_admin_can_update_tenant_security_settings_via_platform_proxy(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();

        $this->patchJson("/api/v1/admin/organizations/{$org->id}/settings/security", [
            'screen_lock_minutes' => 5,
            'session_idle_minutes' => 60,
        ])
            ->assertOk()
            ->assertJsonPath('security.screen_lock_minutes', 5)
            ->assertJsonPath('security.session_idle_minutes', 60);

        $this->assertSame(5, $org->fresh()->module_settings['security']['screen_lock_minutes']);
        $this->assertSame(60, $org->fresh()->module_settings['security']['session_idle_minutes']);
    }

    public function test_tenant_org_admin_cannot_use_erp_settings_security_routes(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/erp/settings/security', [
            'screen_lock_minutes' => 5,
            'session_idle_minutes' => 60,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Organization settings are managed by the platform administrator.');
    }

    public function test_screen_lock_must_be_less_than_sign_out_timeout(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();

        $this->patchJson("/api/v1/admin/organizations/{$org->id}/settings/security", [
            'screen_lock_minutes' => 30,
            'session_idle_minutes' => 20,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['screen_lock_minutes']);
    }

    public function test_capabilities_expose_screen_lock_minutes_from_org_security_settings(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['security'] = array_merge($settings['security'] ?? [], [
            'screen_lock_minutes' => 45,
            'session_idle_minutes' => 120,
        ]);
        $org->update(['module_settings' => $settings]);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('screen_lock_minutes', 45)
            ->assertJsonPath('session_idle_minutes', 120);
    }
}
