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
        $org->forceFill([
            'deployment_profile' => 'distribution',
            'enabled_modules' => array_merge(
                is_array($org->enabled_modules) ? $org->enabled_modules : [],
                ['sales.pos' => false, 'sales.mobile' => true, 'sales.backend' => true],
            ),
        ])->save();

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/users', [
            'full_name' => 'Distribution Driver',
            'username' => 'dist_driver',
            'email' => 'dist_driver@example.test',
            'password' => 'password',
            'access_scope' => 'branch',
            'role_id' => $admin->role_id,
            'branch_id' => $admin->branch_id,
        ])
            ->assertCreated()
            ->assertJson(fn ($json) => $json->where('login_channels', fn ($channels) => is_array($channels)
                && ! in_array('pos', $channels, true)
                && in_array('backoffice', $channels, true)));
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
