<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ManagerAppCapabilitiesTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_capabilities_include_manager_app_for_admin(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)
            ->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('manager_app.accessible', true)
            ->assertJsonPath('manager_app.user_allowed', true);
    }

    public function test_capabilities_mark_manager_app_inaccessible_without_role_permission(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);

        $role = Role::create([
            'role_name' => 'Manager Channel Only',
            'scope' => 'org',
            'is_active' => true,
        ]);

        $user = User::create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'manager_channel_only',
            'email' => 'manager_channel_only@example.com',
            'password' => Hash::make('password'),
            'full_name' => 'Manager Channel Only',
            'is_admin' => false,
            'is_active' => true,
            'access_scope' => 'org',
            'login_channels' => ['backoffice', 'manager'],
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('manager_app.accessible', false)
            ->assertJsonPath('manager_app.user_allowed', false);

        $permissionId = (int) Permission::where('permission_code', 'mobile_manager.app.access')->value('id');
        $role->permissions()->sync([$permissionId]);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('manager_app.accessible', true)
            ->assertJsonPath('manager_app.user_allowed', true);
    }

    public function test_manager_channel_capabilities_keep_org_modules(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);

        $role = Role::create([
            'role_name' => 'Manager With Modules',
            'scope' => 'org',
            'is_active' => true,
        ]);
        $permissionId = (int) Permission::where('permission_code', 'mobile_manager.app.access')->value('id');
        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $permissionId,
        ]);

        $user = User::create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'manager_with_modules',
            'email' => 'manager_with_modules@example.com',
            'password' => Hash::make('password'),
            'full_name' => 'Manager With Modules',
            'is_admin' => false,
            'is_active' => true,
            'access_scope' => 'org',
            'login_channels' => ['manager'],
        ]);

        $plainText = $user->createToken('manager-test', ['*'])->plainTextToken;
        $user->tokens()->latest('id')->first()
            ?->forceFill(['login_channel' => 'manager'])
            ->save();

        $response = $this->withToken($plainText)->getJson('/api/v1/erp/capabilities');
        $response->assertOk()
            ->assertJsonPath('manager_app.accessible', true)
            ->assertJsonStructure(['modules']);

        $modules = $response->json('modules');
        $this->assertIsArray($modules);
        $this->assertArrayHasKey('sales.pos', $modules);
    }

    public function test_manager_login_requires_role_permission_even_with_manager_channel(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);

        $role = Role::create([
            'role_name' => 'No Manager Permission',
            'scope' => 'org',
            'is_active' => true,
        ]);

        User::create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'steve_like',
            'email' => 'steve_like@example.com',
            'password' => Hash::make('password'),
            'full_name' => 'Steve Like',
            'is_admin' => false,
            'is_active' => true,
            'access_scope' => 'org',
            'login_channels' => ['backoffice', 'mobile', 'manager'],
        ]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => $org->company_code,
            'username' => 'steve_like',
            'password' => 'password',
            'client_id' => 'MANAGER_TEST',
            'login_channel' => 'manager',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['login_channel']);
    }
}
