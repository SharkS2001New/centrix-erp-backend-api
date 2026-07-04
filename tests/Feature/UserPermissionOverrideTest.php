<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class UserPermissionOverrideTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_user_can_deny_role_permission(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Test Cashier',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $viewId = (int) Permission::where('permission_code', 'sales.order_queue_all.view')->value('id');
        $createId = (int) Permission::where('permission_code', 'sales.orders.create')->value('id');

        DB::table('role_permissions')->insert([
            ['role_id' => $role->id, 'permission_id' => $viewId],
            ['role_id' => $role->id, 'permission_id' => $createId],
        ]);

        $target = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'limited_cashier',
            'password' => Hash::make('password'),
            'full_name' => 'Limited Cashier',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            'denied_permission_ids' => [(int) $createId],
            'granted_permission_ids' => [],
        ])->assertOk();

        $service = app(UserPermissionService::class);
        $this->assertTrue($service->hasPermission($target->fresh(), 'sales.order_queue_all.view'));
        $this->assertFalse($service->hasPermission($target->fresh(), 'sales.orders.create'));
    }

    public function test_user_can_grant_extra_permission(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'View Only',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $viewId = (int) Permission::where('permission_code', 'sales.order_queue_all.view')->value('id');
        $manageId = (int) Permission::where('permission_code', 'sales.orders.approve')->value('id');

        DB::table('role_permissions')->insert([
            ['role_id' => $role->id, 'permission_id' => $viewId],
        ]);

        $target = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'promoted_viewer',
            'password' => Hash::make('password'),
            'full_name' => 'Promoted Viewer',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            'granted_permission_ids' => [$manageId],
            'denied_permission_ids' => [],
        ])->assertOk();

        $service = app(UserPermissionService::class);
        $this->assertTrue($service->hasPermission($target->fresh(), 'sales.orders.approve'));
        $this->assertTrue($service->hasPermission($target->fresh(), 'sales.manage'));
    }
}
