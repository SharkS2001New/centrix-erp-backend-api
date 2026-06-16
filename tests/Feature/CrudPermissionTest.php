<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CrudPermissionTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function cashierWithoutCatalogueAccess(): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $cashierRole = Role::where('role_name', 'Cashier')->firstOrFail();

        return User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $cashierRole->id,
            'username' => 'cashier_no_catalogue_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Cashier No Catalogue',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);
    }

    public function test_cashier_without_catalogue_permission_cannot_list_products(): void
    {
        Sanctum::actingAs($this->cashierWithoutCatalogueAccess());

        $this->getJson('/api/v1/products')->assertStatus(403);
    }

    public function test_cashier_without_purchasing_permission_cannot_list_suppliers(): void
    {
        Sanctum::actingAs($this->cashierWithoutCatalogueAccess());

        $this->getJson('/api/v1/suppliers')->assertStatus(403);
    }

    public function test_cashier_without_hr_permission_cannot_list_employees(): void
    {
        Sanctum::actingAs($this->cashierWithoutCatalogueAccess());

        $this->getJson('/api/v1/employees')->assertStatus(403);
    }

    public function test_cashier_can_list_sales_with_sales_view_permission(): void
    {
        Sanctum::actingAs($this->cashierWithoutCatalogueAccess());

        $this->getJson('/api/v1/sales')->assertOk();
    }
}
