<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Mobile\MobileAppModuleAccessService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class MobileAppModuleAccessTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_capabilities_include_mobile_app_modules_for_admin(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_mobile_orders' => true,
            'mobile_enable_field_attendance' => true,
        ]);
        $settings['distribution'] = array_merge($settings['distribution'] ?? [], [
            'enable_distribution_ops' => true,
            'mobile_enable_driver_app' => true,
            'mobile_enable_driver_attendance' => true,
        ]);
        $org->update(['module_settings' => $settings]);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/erp/capabilities')
            ->assertOk();

        $mobileApp = $response->json('mobile_app');
        $this->assertFalse($mobileApp['modules_locked']);
        $this->assertTrue($mobileApp['modules']['sales']['accessible']);
        $this->assertTrue($mobileApp['modules']['driver']['accessible']);
    }

    public function test_non_admin_without_driver_permission_cannot_access_driver_api(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['distribution'] = array_merge($settings['distribution'] ?? [], [
            'enable_distribution_ops' => true,
            'mobile_enable_driver_app' => true,
        ]);
        $org->update(['module_settings' => $settings]);

        $clerk = User::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'sales_only_'.uniqid(),
            'email' => null,
            'password' => $admin->password,
            'full_name' => 'Sales Only',
            'is_admin' => false,
            'is_active' => true,
            'login_channels' => ['mobile'],
        ]);

        $this->actingAs($clerk)
            ->getJson('/api/v1/mobile/driver/trips/today')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Your account is not authorized for the driver module.',
            );
    }

    public function test_driver_module_disabled_message_when_org_setting_off(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['distribution'] = array_merge($settings['distribution'] ?? [], [
            'enable_distribution_ops' => true,
            'mobile_enable_driver_app' => false,
        ]);
        $org->update(['module_settings' => $settings]);

        $service = app(MobileAppModuleAccessService::class);
        $gate = app(\App\Services\Erp\CapabilityGate::class)->forOrganization($org);
        $payload = $service->capabilitiesForUser($admin, $gate);

        $this->assertStringContainsString(
            'not enabled for your organization',
            (string) $payload['modules']['driver']['disabled_message'],
        );
    }

    public function test_hotel_home_modules_follow_platform_customization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $modules = config('erp.profiles.hotel_bar.modules');
        $settings = $org->module_settings ?? [];
        $settings['hospitality'] = array_merge($settings['hospitality'] ?? [], [
            'services' => array_merge(
                \App\Services\Hospitality\HospitalityServices::DEFAULTS,
                [
                    'folios' => false,
                    'rooms' => true,
                ],
            ),
        ]);
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'backoffice_sections' => array_merge(
                \App\Services\Erp\BackofficeSections::DEFAULTS,
                [
                    'suppliers' => false,
                    'expenses' => false,
                    'products' => true,
                ],
            ),
        ]);
        $org->forceFill([
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/erp/capabilities')
            ->assertOk();

        $home = $response->json('mobile_app.home_modules');
        $this->assertIsArray($home);
        $this->assertTrue($home['menu_items']['accessible']);
        $this->assertTrue($home['hotel_bar_pos']['accessible']);
        $this->assertTrue($home['past_checks']['accessible']);
        $this->assertFalse($home['vendors']['accessible']);
        $this->assertFalse($home['expenses']['accessible']);
        $this->assertFalse($home['folio_pay']['accessible']);
        $this->assertSame('hospitality', $response->json('mobile_app.industry'));

        // Path middleware should also deny disabled home modules for CentrixAsMobile tokens.
        $issued = $admin->createToken('AS_HOME_MODULES', ['*']);
        $issued->accessToken->forceFill([
            'organization_id' => $admin->organization_id,
            'login_channel' => 'centrix_as_mobile',
        ])->save();

        $this->withToken($issued->plainTextToken)
            ->getJson('/api/v1/suppliers?per_page=1')
            ->assertStatus(403)
            ->assertJsonPath('code', 'login_channel_forbidden');

        $this->withToken($issued->plainTextToken)
            ->getJson('/api/v1/products?per_page=1')
            ->assertOk();
    }
}
