<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LegacyArchiveTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_legacy_archive_status_when_disabled_for_tenant(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/reports/legacy-archive/status')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('available', false);
    }

    public function test_super_admin_can_configure_per_organization_legacy_archive(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();

        $this->patchJson("/api/v1/admin/organizations/{$org->id}/settings/legacy-archive", [
            'enabled' => true,
            'database' => 'lightstores_demo',
            'label' => 'Demo legacy sales',
            'cutover_date' => '2026-06-01',
        ])->assertOk()
            ->assertJsonPath('legacy_archive.enabled', true)
            ->assertJsonPath('legacy_archive.database', 'lightstores_demo')
            ->assertJsonPath('legacy_archive.label', 'Demo legacy sales');

        $org->refresh();
        $this->assertTrue($org->module_settings['legacy_archive']['enabled']);
        $this->assertSame('lightstores_demo', $org->module_settings['legacy_archive']['database']);
    }

    public function test_capabilities_expose_per_organization_legacy_archive_flags(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['legacy_archive'] = [
            'enabled' => true,
            'database' => 'lightstores_demo',
            'label' => 'Demo archive',
            'cutover_date' => null,
        ];
        $org->update(['module_settings' => $settings]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('legacy_archive_enabled', true)
            ->assertJsonPath('legacy_archive_label', 'Demo archive');
    }

    public function test_legacy_orders_api_blocked_when_legacy_archive_disabled(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/legacy-orders')
            ->assertStatus(403)
            ->assertJsonPath('code', 'legacy_archive_disabled');
    }

    public function test_legacy_orders_api_available_when_legacy_archive_enabled(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['legacy_archive'] = array_merge($settings['legacy_archive'] ?? [], ['enabled' => true]);
        $org->update(['module_settings' => $settings]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/legacy-orders')->assertOk();
    }
}
