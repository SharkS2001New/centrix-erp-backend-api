<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class UserLoginChannelTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_mobile_only_user_cannot_login_via_backoffice(): void
    {
        $user = $this->makeUser(['login_channels' => ['mobile']]);

        $response = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'WEB_TEST',
            'login_channel' => 'backoffice',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['login_channel']);
    }

    public function test_pos_only_user_can_login_via_unified_backoffice_channel(): void
    {
        $user = $this->makeUser(['login_channels' => ['pos']]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'WEB_UNIFIED',
            'login_channel' => 'backoffice',
        ])->assertOk();
    }

    public function test_mobile_only_user_can_login_via_mobile(): void
    {
        $user = $this->makeUser(['login_channels' => ['mobile']]);

        $response = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'MOBILE_TEST',
            'login_channel' => 'mobile',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user', 'organization']);
    }

    public function test_mobile_login_blocked_when_org_mobile_orders_disabled(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], ['enable_mobile_orders' => false]);
        $org->forceFill(['module_settings' => $settings])->save();

        $user = $this->makeUser(['login_channels' => ['mobile']]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'MOBILE_DISABLED_ORG',
            'login_channel' => 'mobile',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login_channel']);
    }

    public function test_all_channel_user_can_login_from_any_client(): void
    {
        $user = $this->makeUser(['login_channels' => ['backoffice', 'pos', 'mobile']]);

        foreach (['backoffice', 'pos', 'mobile'] as $index => $channel) {
            $response = $this->postJson('/api/v1/auth/login', [
                'company_code' => 'DEMO',
                'username' => $user->username,
                'password' => 'password',
                'client_id' => 'CLIENT_'.$channel,
                'login_channel' => $channel,
                'force_logout' => $index > 0,
            ]);

            $response->assertOk();
        }
    }

    public function test_backoffice_only_user_can_login_via_mobile_when_org_allows_mobile(): void
    {
        // Hotel/AS staff are often seeded with backoffice channels only while the org
        // enables the mobile login channel for outlet shop ops.
        $user = $this->makeUser(['login_channels' => ['backoffice']]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'MOBILE_BACKOFFICE_BRIDGE',
            'login_channel' => 'mobile',
        ])->assertOk();
    }

    public function test_hotel_bar_org_allows_backoffice_and_mobile_login_for_staff(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->forceFill([
            'deployment_profile' => 'hotel_bar',
            // Sparse map — full profile modules as source of truth for Hotel & Bar.
            'enabled_modules' => config('erp.profiles.hotel_bar.modules'),
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        $gate = app(\App\Services\Erp\CapabilityGate::class)->forOrganization($org->fresh());
        $this->assertTrue($gate->mobileSalesEnabled());
        $this->assertContains('mobile', $gate->allowedLoginChannels());
        $this->assertContains('backoffice', $gate->allowedLoginChannels());
        $this->assertNotContains('pos', $gate->allowedLoginChannels());

        // Typical hotel cashier: web/backoffice channels only, signs into AS mobile.
        $user = $this->makeUser([
            'login_channels' => ['backoffice'],
            'is_admin' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'HOTEL_WEB',
            'login_channel' => 'backoffice',
            'force_logout' => true,
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'HOTEL_AS_MOBILE',
            'login_channel' => 'mobile',
            'force_logout' => true,
        ])->assertOk()
            ->assertJsonPath('user.login_channels', ['backoffice']);
    }

    public function test_hotel_bar_rejects_external_pos_login_channel(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->forceFill([
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => config('erp.profiles.hotel_bar.modules'),
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        $user = $this->makeUser(['login_channels' => ['pos', 'backoffice']]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'HOTEL_EXTERNAL_POS',
            'login_channel' => 'pos',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login_channel']);
    }

    public function test_centrix_as_mobile_channel_allows_as_app_login(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->forceFill([
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => config('erp.profiles.hotel_bar.modules'),
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        $user = $this->makeUser([
            'login_channels' => ['centrix_as_mobile'],
            'is_admin' => false,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'AS_MOBILE_CHANNEL',
            'login_channel' => 'centrix_as_mobile',
            'force_logout' => true,
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user', 'organization']);

        $this->assertNotEmpty($login->json('token'));
    }

    public function test_centrix_as_mobile_login_blocked_without_channel(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->forceFill([
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => config('erp.profiles.hotel_bar.modules'),
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        // Manager-only, non-admin, no web channels — must not use CentrixAsMobile.
        $user = $this->makeUser([
            'login_channels' => ['manager'],
            'is_admin' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'AS_MOBILE_DENIED',
            'login_channel' => 'centrix_as_mobile',
            'force_logout' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login_channel']);
    }

    public function test_hotel_backoffice_user_can_login_via_centrix_as_mobile(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->forceFill([
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => config('erp.profiles.hotel_bar.modules'),
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        $user = $this->makeUser([
            'login_channels' => ['backoffice'],
            'is_admin' => false,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'HOTEL_AS_BRIDGE',
            'login_channel' => 'centrix_as_mobile',
            'force_logout' => true,
        ])->assertOk();

        $token = $login->json('token');
        $this->assertNotEmpty($token);
        $this->assertSame('centrix_as_mobile', $login->json('login_channel'));

        // Soft-allow login must not 403 every CentrixAsMobile page afterward.
        $this->withToken($token)
            ->getJson('/api/v1/products?per_page=5')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/suppliers?per_page=5')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/hospitality/pos/catalog')
            ->assertSuccessful();

        $this->withToken($token)
            ->getJson('/api/v1/hospitality/checks')
            ->assertSuccessful();

        $this->withToken($token)
            ->getJson('/api/v1/stock-take-sessions')
            ->assertSuccessful();
    }

    public function test_as_mobile_client_remaps_legacy_mobile_channel_to_centrix_as_mobile(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->forceFill([
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => config('erp.profiles.hotel_bar.modules'),
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        $user = $this->makeUser([
            'login_channels' => ['centrix_as_mobile'],
            'is_admin' => false,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'ASMOBILE_LEGACY_BUILD',
            'login_channel' => 'mobile',
            'force_logout' => true,
        ])->assertOk();

        $this->assertSame('centrix_as_mobile', $login->json('login_channel'));
    }

    public function test_legacy_field_mobile_channel_still_allows_centrix_as_mobile_login(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->forceFill([
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => config('erp.profiles.hotel_bar.modules'),
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        $user = $this->makeUser([
            'login_channels' => ['mobile'],
            'is_admin' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'ASMOBILE_LEGACY_USER',
            'login_channel' => 'centrix_as_mobile',
            'force_logout' => true,
        ])->assertOk();
    }

    public function test_mobile_session_cannot_access_admin_users_api(): void
    {
        $user = $this->makeUser(['login_channels' => ['mobile']]);

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'MOBILE_ADMIN_BLOCK',
            'login_channel' => 'mobile',
        ])->assertOk();

        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'login_channel_forbidden');
    }

    public function test_mobile_session_can_access_notifications_and_issue_reports(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $routeId = (int) (\App\Models\RouteModel::query()->value('id') ?? 1);
        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'mobile_notify_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Mobile Notify User',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['mobile'],
            'mobile_order_scope' => 'route_only',
            'assigned_route_id' => $routeId,
            'is_active' => true,
        ]);

        $issued = $user->createToken('MOBILE_NOTIFY_TEST', ['*']);
        $issued->accessToken->forceFill([
            'organization_id' => $user->organization_id,
            'login_channel' => 'mobile',
        ])->save();
        $token = $issued->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/system-issue-reports', [
                'kind' => 'user_report',
                'message' => 'Mobile app test issue',
                'reported_by_user' => true,
            ])
            ->assertCreated();
    }

    public function test_mobile_session_can_access_allowlisted_sales_reports(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $routeId = (int) (\App\Models\RouteModel::query()->value('id') ?? 1);
        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'mobile_reports_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Mobile Reports User',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['mobile'],
            'mobile_order_scope' => 'route_only',
            'assigned_route_id' => $routeId,
            'is_active' => true,
        ]);

        $issued = $user->createToken('MOBILE_REPORTS_TEST', ['*']);
        $issued->accessToken->forceFill([
            'organization_id' => $user->organization_id,
            'login_channel' => 'mobile',
        ])->save();
        $token = $issued->plainTextToken;
        $today = now()->toDateString();

        $this->withToken($token)
            ->getJson("/api/v1/reports/sales-by-supplier?from_date={$today}&to_date={$today}&date_column=sale_date&per_page=10")
            ->assertOk();

        $this->withToken($token)
            ->getJson("/api/v1/reports/sales-by-product?from_date={$today}&to_date={$today}&date_column=sale_date&per_page=10")
            ->assertOk();

        $this->withToken($token)
            ->getJson("/api/v1/reports/eod-report?from_date={$today}&to_date={$today}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'login_channel_forbidden');
    }

    public function test_pos_session_can_access_pos_sales_and_branches_but_not_admin_users(): void
    {
        $user = $this->makeUser(['login_channels' => ['pos']]);

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'POS_TERMINAL',
            'login_channel' => 'pos',
        ])->assertOk();

        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/branches')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/tills')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/products')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk();

        $this->withToken($token)
            ->getJson("/api/v1/users/{$user->id}")
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/kra-responses?per_page=1')
            ->assertOk();

        // Cheap capabilities stamp poll used by AuthProvider while on /pos.
        $this->withToken($token)
            ->getJson('/api/v1/erp/capabilities/version')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'login_channel_forbidden');
    }

    public function test_demo_cashier_can_search_products_for_pos_checkout(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => 'cashier',
            'password' => 'password',
            'client_id' => 'POS_CASHIER_CATALOGUE',
            'login_channel' => 'pos',
        ])->assertOk();

        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/products?q=rice&per_page=10')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->withToken($token)
            ->getJson('/api/v1/uoms?per_page=10')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/retail-package-settings?per_page=10')
            ->assertOk();
    }

    public function test_admin_can_set_user_login_channels(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $target = $this->makeUser(['login_channels' => ['backoffice', 'pos', 'mobile']]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/users/{$target->id}", [
                'login_channels' => ['mobile'],
            ])
            ->assertOk()
            ->assertJsonPath('login_channels', ['mobile'])
            ->assertJsonPath('is_mobile_user', true);
    }

    public function test_pos_channel_rejected_when_external_pos_disabled_for_organization(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->forceFill([
            'deployment_profile' => 'distribution',
            'enabled_modules' => array_merge(
                is_array($org->enabled_modules) ? $org->enabled_modules : [],
                ['sales.pos' => false],
            ),
        ])->save();

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/users', [
            'full_name' => 'Distribution Rep',
            'username' => 'dist_rep',
            'email' => 'dist_rep@example.test',
            'password' => 'password',
            'access_scope' => 'branch',
            'role_id' => $admin->role_id,
            'branch_id' => $admin->branch_id,
            'login_channels' => ['backoffice', 'pos', 'mobile'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login_channels']);
    }

    public function test_user_defaults_to_org_allowed_login_channels_without_pos(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        // Sparse "on" keys only — omit sales.pos entirely. Dense maps that store
        // sales.pos=false get compacted and can re-enable POS via sales cascade.
        $org->forceFill([
            'deployment_profile' => 'distribution',
            'enabled_modules' => [
                'sales' => true,
                'sales.backend' => true,
                'sales.mobile' => true,
                'sales.dashboard' => true,
                'sales.reports' => true,
                'distribution' => true,
                'inventory' => true,
                'admin' => true,
                'customers_suppliers' => true,
            ],
        ])->save();
        app(\App\Services\Platform\OrganizationLicenseService::class)
            ->forgetResolvedLicense((int) $org->id);

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/v1/users', [
            'full_name' => 'Distribution Driver',
            'username' => 'dist_driver',
            'email' => 'dist_driver@example.test',
            'password' => 'password',
            'access_scope' => 'branch',
            'role_id' => $admin->role_id,
            'branch_id' => $admin->branch_id,
        ])->assertCreated();

        $channels = $created->json('login_channels');
        $this->assertIsArray($channels);
        $this->assertNotContains('pos', $channels);
        $this->assertContains('backoffice', $channels);
    }

    public function test_pos_login_blocked_when_external_pos_disabled_for_organization(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->forceFill([
            'enabled_modules' => array_merge(
                is_array($org->enabled_modules) ? $org->enabled_modules : [],
                ['sales.pos' => false],
            ),
        ])->save();

        $user = $this->makeUser(['login_channels' => ['pos']]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'POS_DISABLED_ORG',
            'login_channel' => 'pos',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login_channel']);
    }
    public function test_mobile_and_backoffice_sessions_can_coexist(): void
    {
        $user = $this->makeUser(['login_channels' => ['backoffice', 'pos', 'mobile']]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'ERP_BROWSER',
            'login_channel' => 'backoffice',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'LIGHTSTORES_MOBILE',
            'login_channel' => 'mobile',
        ])->assertOk();
    }

    public function test_switch_workspace_to_pos_succeeds_when_pos_session_active_elsewhere(): void
    {
        $user = $this->makeUser(['login_channels' => ['backoffice', 'pos', 'mobile']]);

        $backoffice = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'BACKOFFICE_BROWSER',
            'login_channel' => 'backoffice',
        ])->assertOk()->json();

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'POS_TERMINAL',
            'login_channel' => 'pos',
        ])->assertOk();

        $this->withToken($backoffice['token'])
            ->postJson('/api/v1/auth/switch-workspace', [
                'client_id' => 'BACKOFFICE_BROWSER',
                'login_channel' => 'pos',
                'workspace_id' => 'pos',
            ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_mobile_capabilities_payload_is_slimmed_for_mobile_channel(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = is_array($org->module_settings) ? $org->module_settings : [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_mobile_orders' => true,
        ]);
        $org->forceFill(['module_settings' => $settings])->save();

        $user = $this->makeUser([
            'login_channels' => ['mobile'],
            'is_admin' => true,
        ]);

        $token = $user->createToken('MOBILE_SLIM_TEST', ['*']);
        $token->accessToken->forceFill([
            'organization_id' => $user->organization_id,
            'login_channel' => 'mobile',
        ])->save();

        $response = $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/erp/capabilities')
            ->assertOk();

        $capabilities = $response->json();
        $this->assertArrayHasKey('mobile_app', $capabilities);
        $this->assertArrayHasKey('permissions', $capabilities);
        $this->assertArrayNotHasKey('workspaces', $capabilities);
        $this->assertArrayNotHasKey('modules', $capabilities);
        $this->assertArrayNotHasKey('assigned_permissions', $capabilities);
        $this->assertArrayNotHasKey('manager_app', $capabilities);
        $this->assertArrayNotHasKey('workflows', $capabilities);

        $moduleSettings = $capabilities['module_settings'] ?? [];
        $this->assertArrayHasKey('sales', $moduleSettings);
        $this->assertArrayNotHasKey('accounting', $moduleSettings);
        $this->assertArrayNotHasKey('hospitality', $moduleSettings);
        $this->assertArrayNotHasKey('hr', $moduleSettings);

        foreach (array_keys($capabilities['permissions'] ?? []) as $code) {
            $this->assertTrue(
                str_starts_with((string) $code, 'mobile')
                    || $code === 'sales.discounts.give'
                    || str_starts_with((string) $code, 'approvals.')
                    || str_starts_with((string) $code, 'discount'),
                "Unexpected permission on mobile capabilities payload: {$code}",
            );
        }

        // Login-embedded capabilities must also slim even before the Bearer token is current.
        $loginCaps = app(\App\Http\Controllers\Api\V1\ErpCapabilitiesController::class)
            ->resolveForUser($user, 'mobile');
        $this->assertArrayNotHasKey('workspaces', $loginCaps);
        $this->assertArrayNotHasKey('modules', $loginCaps);
        $this->assertArrayHasKey('mobile_app', $loginCaps);
    }

    protected function makeUser(array $overrides = []): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        return User::create(array_merge([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'channel_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Channel Test User',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['backoffice', 'pos', 'mobile'],
            'is_active' => true,
        ], $overrides));
    }
}
