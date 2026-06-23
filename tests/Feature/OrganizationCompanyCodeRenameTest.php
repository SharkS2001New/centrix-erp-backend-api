<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrganizationCompanyCodeRenameTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_rename_company_code_and_keep_old_alias_for_login(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'PVT-RG73J971',
            'org_name' => 'Moonlight Express Ltd',
            'org_email' => 'moon@example.com',
            'primary_tel' => '0711000001',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => ['sales' => true, 'admin' => true],
            'admin_username' => 'moon_admin',
            'admin_email' => 'moon@example.com',
            'admin_password' => 'Password123',
            'admin_full_name' => 'Moon Admin',
        ])->assertCreated();

        $org = Organization::where('company_code', 'PVT-RG73J971')->firstOrFail();

        $this->patchJson("/api/v1/admin/organizations/{$org->id}/company-code", [
            'company_code' => 'MOON',
        ])->assertOk()
            ->assertJsonPath('organization.company_code', 'MOON')
            ->assertJsonPath('organization.company_code_aliases', ['PVT-RG73J971']);

        $this->getJson('/api/v1/auth/organization-preview?company_code=MOON')
            ->assertOk()
            ->assertJsonPath('company_code', 'MOON');

        $this->getJson('/api/v1/auth/organization-preview?company_code=PVTRG73J971')
            ->assertOk()
            ->assertJsonPath('company_code', 'MOON');

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'PVT-RG73J971',
            'username' => 'moon_admin',
            'password' => 'Password123',
            'client_id' => 'WEB_TEST',
        ])->assertOk()
            ->assertJsonPath('organization.company_code', 'MOON');
    }

    public function test_legacy_archive_company_code_is_preserved_when_renaming_with_archive_enabled(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['legacy_archive'] = [
            'enabled' => true,
            'database' => 'lightstores_demo',
            'label' => 'Demo legacy',
        ];
        $org->forceFill(['module_settings' => $settings])->save();

        $this->patchJson("/api/v1/admin/organizations/{$org->id}/company-code", [
            'company_code' => 'DEMOSHOP',
        ])->assertOk();

        $org->refresh();
        $this->assertSame('DEMOSHOP', $org->company_code);
        $this->assertSame('DEMO', $org->module_settings['legacy_archive']['legacy_company_code'] ?? null);
        $this->assertTrue($org->matchesLegacyCompanyCode('DEMO'));
    }
}
