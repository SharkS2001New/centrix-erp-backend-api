<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PermissionAliasTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_feature_edit_permission_satisfies_route_capability(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Sales Editor',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $editId = (int) Permission::where('permission_code', 'sales.orders.edit')->value('id');
        $this->assertNotNull($editId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $editId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'sales_editor',
            'password' => Hash::make('password'),
            'full_name' => 'Sales Editor',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        $service = app(UserPermissionService::class);
        $this->assertTrue($service->hasPermission($user->fresh(), 'sales.manage'));
        $this->assertTrue($service->permissionMapForUser($user->fresh())['sales.manage']);
    }

    public function test_catalogue_product_edit_satisfies_products_manage(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Catalogue Editor',
            'scope' => 'org',
            'is_active' => true,
        ]);

        $editId = (int) Permission::where('permission_code', 'catalogue.products.edit')->value('id');
        $this->assertNotNull($editId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $editId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'catalogue_editor',
            'password' => Hash::make('password'),
            'full_name' => 'Catalogue Editor',
            'access_scope' => 'org',
            'is_active' => true,
        ]);

        $service = app(UserPermissionService::class);
        $this->assertTrue($service->hasPermission($user->fresh(), 'products.manage'));
    }
}
