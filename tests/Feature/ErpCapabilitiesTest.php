<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Erp\PermissionMatrixService;
use App\Services\Cache\OrganizationCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ErpCapabilitiesTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_capabilities_masks_mpesa_secrets(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $org = Organization::findOrFail($user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'mpesa' => [
                'env' => 'sandbox',
                'consumer_key' => 'demo-key',
                'consumer_secret' => 'super-secret',
                'shortcode' => '600000',
                'till_number' => '600001',
                'passkey' => 'demo-passkey',
                'stk_callback_url' => 'https://example.com/api/v1/payments/stk/callback',
            ],
        ]);
        $org->update(['module_settings' => $settings]);

        $response = $this->getJson('/api/v1/erp/capabilities')->assertOk();
        $mpesa = $response->json('module_settings.finance.mpesa');

        $this->assertSame('demo-key', $mpesa['consumer_key']);
        $this->assertSame('********', $mpesa['consumer_secret']);
        $this->assertSame('********', $mpesa['passkey']);
        $this->assertNotSame('super-secret', $mpesa['consumer_secret']);
        $this->assertNotSame('demo-passkey', $mpesa['passkey']);
    }

    public function test_capabilities_include_generation_version(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/erp/capabilities')->assertOk();
        $response->assertJsonPath('centrix_version', \App\Support\CentrixVersion::current());
        $response->assertJsonPath('centrix_version_label', \App\Support\CentrixVersion::label());
        $this->assertSame(
            OrganizationCache::capabilitiesVersion((int) $user->organization_id),
            $response->json('capabilities_version'),
        );
    }

    public function test_allow_org_provisioning_reflects_current_config_not_stale_cache(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        config(['erp.allow_org_provisioning' => false]);
        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('allow_org_provisioning', false);

        config(['erp.allow_org_provisioning' => true]);
        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('allow_org_provisioning', true);
    }

    public function test_capabilities_reflect_platform_mpesa_stk_toggle_even_when_cached(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $org = Organization::findOrFail($user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_mpesa_stk' => true,
            'mpesa' => [
                'enable_stk_push' => true,
                'env' => 'sandbox',
                'consumer_key' => 'demo-key',
                'consumer_secret' => 'super-secret',
                'shortcode' => '600000',
                'till_number' => '600001',
                'passkey' => 'demo-passkey',
                'stk_callback_url' => 'https://example.com/api/v1/payments/stk/callback',
            ],
        ]);
        $org->update(['module_settings' => $settings]);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('platform_mpesa_stk_enabled', true)
            ->assertJsonPath('module_settings.finance.mpesa.consumer_key', 'demo-key');

        $settings['finance']['enable_mpesa_stk'] = false;
        $org->update(['module_settings' => $settings]);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('platform_mpesa_stk_enabled', false)
            ->assertJsonMissingPath('module_settings.finance.mpesa');
    }

    public function test_permission_sync_invalidates_org_capabilities_cache(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Cache Test Viewer',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $viewId = (int) Permission::where('permission_code', 'sales.orders.view')->value('id');
        $createId = (int) Permission::where('permission_code', 'sales.orders.create')->value('id');

        DB::table('role_permissions')->insert([
            ['role_id' => $role->id, 'permission_id' => $viewId],
        ]);

        $target = User::create([
            'organization_id' => $orgId,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'cache_test_user',
            'password' => Hash::make('password'),
            'full_name' => 'Cache Test User',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        Sanctum::actingAs($target);
        $before = $this->getJson('/api/v1/erp/capabilities')->assertOk();
        // Ungranted codes are omitted from the map (not stored as false).
        $this->assertTrue(! ($before->json('permissions.sales.orders.create') ?? false));

        $versionBefore = OrganizationCache::capabilitiesVersion($orgId);

        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            'granted_permission_ids' => [$createId],
            'denied_permission_ids' => [],
        ])->assertOk();

        $this->assertGreaterThan($versionBefore, OrganizationCache::capabilitiesVersion($orgId));

        Sanctum::actingAs($target);
        $after = $this->getJson('/api/v1/erp/capabilities')->assertOk();
        // Flat permission codes contain dots — do not use assertJsonPath (nested walk).
        $this->assertTrue((bool) ($after->json('permissions')['sales.orders.create'] ?? false));
    }
}
