<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\ApplicationProvisioner;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CentrixAsMobileOrdersAccessTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_shop_as_mobile_can_list_orders_without_field_mobile_module(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $modules = app(ApplicationProvisioner::class)->enabledModulesFromApplications([
            'pos' => true,
            'backoffice' => true,
            'distribution' => false,
            'accounting' => false,
            'hr' => false,
            'admin' => true,
            'hotel_bar_pos' => false,
            'hospitality_backoffice' => false,
        ]);

        $this->assertTrue((bool) ($modules['sales.pos'] ?? false));
        $this->assertTrue((bool) ($modules['sales.backend'] ?? false));
        $this->assertFalse((bool) ($modules['sales.mobile'] ?? false));

        $org->forceFill([
            'deployment_profile' => 'supermarket',
            'enabled_modules' => $modules,
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        $admin = User::where('username', 'admin')->firstOrFail();
        $user = User::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'as_orders_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'AS Orders User',
            'is_admin' => true,
            'access_scope' => 'org',
            'login_channels' => ['backoffice', 'pos'],
            'is_active' => true,
        ]);

        $issued = $user->createToken('AS_ORDERS', ['*']);
        $issued->accessToken->forceFill([
            'organization_id' => $user->organization_id,
            'login_channel' => 'centrix_as_mobile',
        ])->save();

        $today = now()->toDateString();

        $this->withToken($issued->plainTextToken)
            ->getJson("/api/v1/mobile/orders?from_date={$today}&to_date={$today}&all_channels=1&per_page=10")
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->withToken($issued->plainTextToken)
            ->getJson('/api/v1/mobile/reconciliation?all_channels=1')
            ->assertOk();
    }

    public function test_hotel_as_mobile_can_list_orders_without_sales_mobile_app(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $modules = app(ApplicationProvisioner::class)->enabledModulesFromApplications([
            'hotel_bar_pos' => true,
            'hospitality_backoffice' => true,
            'accounting' => false,
            'hr' => false,
            'admin' => true,
            'pos' => false,
            'backoffice' => false,
            'distribution' => false,
        ]);

        $this->assertTrue((bool) ($modules['hospitality.bar_pos'] ?? false));
        $this->assertFalse((bool) ($modules['sales.mobile'] ?? false));

        $org->forceFill([
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => $modules,
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        $admin = User::where('username', 'admin')->firstOrFail();
        $user = User::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'hotel_orders_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Hotel Orders User',
            'is_admin' => true,
            'access_scope' => 'org',
            'login_channels' => ['backoffice'],
            'is_active' => true,
        ]);

        $issued = $user->createToken('AS_HOTEL_ORDERS', ['*']);
        $issued->accessToken->forceFill([
            'organization_id' => $user->organization_id,
            'login_channel' => 'centrix_as_mobile',
        ])->save();

        $today = now()->toDateString();

        $this->withToken($issued->plainTextToken)
            ->getJson("/api/v1/mobile/orders?from_date={$today}&to_date={$today}&all_channels=1&per_page=10")
            ->assertOk()
            ->assertJsonStructure(['data'])
            ->assertJsonMissingPath('module');
    }
}
