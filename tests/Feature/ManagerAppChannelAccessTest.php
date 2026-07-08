<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ManagerAppChannelAccessTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_manager_session_can_access_dispatch_trips_and_reports(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);

        $role = Role::create([
            'role_name' => 'Manager App User',
            'scope' => 'org',
            'is_active' => true,
        ]);

        $permissionId = (int) Permission::where('permission_code', 'mobile_manager.app.access')->value('id');
        $role->permissions()->sync([$permissionId]);

        $user = User::create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'manager_app_user',
            'email' => 'manager_app_user@example.com',
            'password' => Hash::make('password'),
            'full_name' => 'Manager App User',
            'is_admin' => false,
            'is_active' => true,
            'access_scope' => 'org',
            'login_channels' => ['manager'],
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => $org->company_code,
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'MANAGER_CHANNEL_TEST',
            'login_channel' => 'manager',
        ])->assertOk();

        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/reports/dispatch-trips?per_page=5')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/dispatch-trips?per_page=5')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/products?per_page=5')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/admin/organizations')
            ->assertStatus(403)
            ->assertJsonPath('code', 'login_channel_forbidden');
    }
}
