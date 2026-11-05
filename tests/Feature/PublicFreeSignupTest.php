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
            'setup_type' => 'small_shop',
            'business_type' => 'Pharmacy',
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
                'industry',
                'business_type',
                'setup_type',
                'setup_type_label',
            ]);

        $companyCode = (string) $response->json('company_code');
        $loginUrl = (string) $response->json('login_url');
        $this->assertStringContainsString('/login?', $loginUrl);
        $this->assertStringContainsString('company_code='.rawurlencode($companyCode), $loginUrl);
        $this->assertStringContainsString('username=owner', $loginUrl);
        $this->assertStringContainsString('from=free-signup', $loginUrl);

        $org = Organization::query()->where('company_code', $companyCode)->firstOrFail();
        $this->assertSame('small_shop', $org->deployment_profile);
        $this->assertSame('Pharmacy', $org->business_type);
        $this->assertSame(
            ['booked', 'unpaid', 'pending_payment', 'paid', 'completed'],
            array_column($org->module_settings['sales']['order_workflow']['steps'], 'status'),
        );
        $this->assertSame(['booked', 'editable'], $org->module_settings['sales']['edit_order_statuses']);
        $this->assertSame(['paid', 'completed'], $org->module_settings['sales']['customer_return_statuses']);
        $response->assertJsonPath('setup_type_label', 'Small shop (backoffice sales only)');
        $this->assertSame('self_registered', $org->registration_source);
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
            ->assertJsonStructure(['industries', 'setup_types', 'free_plan_name', 'login_url'])
            ->assertJsonFragment([
                'id' => 'small_shop',
                'label' => 'Small shop (backoffice sales only)',
                'industry' => 'commerce',
            ]);
    }

    public function test_public_free_signup_rejects_a_setup_type_from_a_different_industry(): void
    {
        config(['erp.allow_public_free_signup' => true]);

        $this->postJson('/api/v1/public/free-signup', [
            'org_name' => 'Lodge Test',
            'org_email' => 'owner@lodgetest.test',
            'primary_tel' => '0712345678',
            'industry' => 'commerce',
            'setup_type' => 'hotel_bar',
            'business_type' => 'Lodge',
            'admin_full_name' => 'Lodge Owner',
            'admin_password' => 'SecurePass1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('setup_type');
    }

    public function test_public_free_signup_provisions_the_selected_hospitality_setup(): void
    {
        config(['erp.allow_public_free_signup' => true]);

        $response = $this->postJson('/api/v1/public/free-signup', [
            'org_name' => 'Lake View Lodge',
            'org_email' => 'owner@lakeviewlodge.test',
            'primary_tel' => '0712345678',
            'industry' => 'hospitality',
            'setup_type' => 'hotel_bar',
            'business_type' => 'Lodge',
            'admin_full_name' => 'Lodge Owner',
            'admin_password' => 'SecurePass1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('industry', 'hospitality')
            ->assertJsonPath('business_type', 'Lodge')
            ->assertJsonPath('setup_type', 'hotel_bar')
            ->assertJsonPath('setup_type_label', 'Hotel & Bar');

        $organization = Organization::query()
            ->where('company_code', $response->json('company_code'))
            ->firstOrFail();
        $this->assertSame('hotel_bar', $organization->deployment_profile);
        $this->assertSame('Lodge', $organization->business_type);
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
