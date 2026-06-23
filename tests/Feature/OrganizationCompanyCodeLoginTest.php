<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrganizationCompanyCodeLoginTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_login_and_preview_match_hyphenated_company_codes_without_hyphens(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'HY-PHEN01',
            'org_name' => 'Hyphen Org Ltd',
            'org_email' => 'hyphen@org.com',
            'primary_tel' => '0711000099',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ['sales' => true, 'sales.pos' => true, 'admin' => true],
            'admin_username' => 'hyphen_admin',
            'admin_email' => 'hyphen@org.com',
            'admin_password' => 'Password123',
            'admin_full_name' => 'Hyphen Admin',
        ])->assertCreated();

        $this->getJson('/api/v1/auth/organization-preview?company_code=HYPHEN01')
            ->assertOk()
            ->assertJsonPath('company_code', 'HY-PHEN01');

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'HYPHEN01',
            'username' => 'hyphen_admin',
            'password' => 'Password123',
            'client_id' => 'WEB_TEST',
        ])->assertOk()
            ->assertJsonPath('organization.company_code', 'HY-PHEN01');
    }

    public function test_login_matches_usernames_case_insensitively(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $user = User::where('organization_id', $org->id)->where('username', 'admin')->firstOrFail();
        $user->forceFill([
            'username' => 'ADMIN',
            'password' => Hash::make('Password123'),
            'must_change_password' => false,
        ])->save();

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => 'admin',
            'password' => 'Password123',
            'client_id' => 'WEB_TEST',
        ])->assertOk()
            ->assertJsonPath('user.username', 'ADMIN');
    }

    public function test_super_admin_username_login_is_case_insensitive(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'PLATFORM',
            'username' => 'SuperAdmin',
            'password' => 'password',
            'client_id' => 'PC_PLATFORM',
        ])->assertOk()
            ->assertJsonPath('user.is_super_admin', true);
    }
}
