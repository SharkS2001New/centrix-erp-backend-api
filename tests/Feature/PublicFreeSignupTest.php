<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Services\Platform\OrganizationLicenseService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PublicFreeSignupTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_public_free_signup_creates_package_one_organisation(): void
    {
        config(['erp.allow_public_free_signup' => true]);

        $response = $this->postJson('/api/v1/public/free-signup', [
            'org_name' => 'Kileleshwa Spares',
            'org_email' => 'owner@kileleshwaspares.test',
            'primary_tel' => '0712345678',
            'industry' => 'commerce',
            'admin_full_name' => 'Anne Owner',
            'admin_password' => 'SecurePass1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('username', 'owner')
            ->assertJsonStructure([
                'company_code',
                'username',
                'login_url',
                'free_plan_name',
            ]);

        $companyCode = (string) $response->json('company_code');
        $loginUrl = (string) $response->json('login_url');
        $this->assertStringContainsString('/login?', $loginUrl);
        $this->assertStringContainsString('company_code='.rawurlencode($companyCode), $loginUrl);
        $this->assertStringContainsString('username=owner', $loginUrl);
        $this->assertStringContainsString('from=free-signup', $loginUrl);

        $org = Organization::query()->where('company_code', $companyCode)->firstOrFail();
        $this->assertSame('wholesale_retail', $org->deployment_profile);
        $this->assertSame(0, PlatformSubscription::query()->where('organization_id', $org->id)->count());

        $licenses = app(OrganizationLicenseService::class);
        $license = $licenses->resolveForOrganization($org);
        $this->assertTrue($licenses->usesPackageOne($license));
        $this->assertSame('free', $licenses->accessMode($license));
    }

    public function test_public_free_signup_options_list_industries(): void
    {
        config(['erp.allow_public_free_signup' => true]);

        $this->getJson('/api/v1/public/free-signup')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonStructure(['industries', 'free_plan_name', 'login_url']);
    }

    public function test_public_free_signup_can_be_disabled(): void
    {
        config(['erp.allow_public_free_signup' => false]);

        $this->postJson('/api/v1/public/free-signup', [
            'org_name' => 'Blocked Shop',
            'org_email' => 'blocked@example.test',
            'primary_tel' => '0700000000',
            'industry' => 'commerce',
            'admin_full_name' => 'Blocked User',
            'admin_password' => 'SecurePass1',
        ])->assertStatus(422);
    }
}
