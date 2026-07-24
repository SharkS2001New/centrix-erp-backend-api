<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\PlatformSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OperationalReportPermissionTest extends TestCase
{
    use RefreshesErpDatabase;

    private function seedOrgSubscription(int $organizationId): void
    {
        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );
    }

    /** @param  list<string>  $permissionCodes */
    private function createRoleUser(array $permissionCodes, string $usernamePrefix): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedOrgSubscription((int) $admin->organization_id);

        $role = Role::query()->firstOrCreate(
            ['role_name' => ucfirst($usernamePrefix).' Role '.uniqid()],
            ['scope' => 'branch', 'is_active' => true],
        );

        $permissionIds = Permission::query()
            ->whereIn('permission_code', $permissionCodes)
            ->pluck('id');
        $this->assertSame(count($permissionCodes), $permissionIds->count(), 'Missing permission codes: '.implode(', ', $permissionCodes));

        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
        }

        return User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => $usernamePrefix.'_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => ucfirst($usernamePrefix).' User',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice'],
            'is_active' => true,
        ]);
    }

    public function test_pos_end_of_day_permission_can_load_eod_report(): void
    {
        Organization::query()->where('company_code', 'DEMO')->update([
            'enabled_modules' => [
                'sales' => true,
                'sales.backend' => true,
                'sales.pos' => true,
                'sales.reports' => true,
            ],
        ]);

        $user = $this->createRoleUser(['pos.end_of_day.view'], 'eod');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/eod-report?sale_date='.now()->toDateString().'&per_page=5')
            ->assertOk();
    }

    public function test_inventory_permission_can_load_stock_on_hand_without_reports_hub(): void
    {
        Organization::query()->where('company_code', 'DEMO')->update([
            'enabled_modules' => [
                'inventory' => true,
                'inventory.reports' => true,
            ],
        ]);

        $user = $this->createRoleUser(['inventory.stock.view'], 'stock');
        Sanctum::actingAs($user);

        $branchId = (int) $user->branch_id;
        $this->getJson("/api/v1/reports/stock-on-hand?branch_id={$branchId}&per_page=5")
            ->assertOk();
    }

    public function test_purchasing_permission_can_load_open_lpo_without_reports_hub(): void
    {
        Organization::query()->where('company_code', 'DEMO')->update([
            'enabled_modules' => [
                'customers_suppliers' => true,
                'customers_suppliers.reports' => true,
            ],
        ]);

        $user = $this->createRoleUser(['purchasing.lpo.view'], 'lpo');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/open-lpo?per_page=5')
            ->assertOk();
    }

    public function test_accounting_permission_can_load_ar_aging_without_reports_hub(): void
    {
        Organization::query()->where('company_code', 'DEMO')->update([
            'enabled_modules' => [
                'accounting' => true,
                'accounting.reports' => true,
            ],
        ]);

        $user = $this->createRoleUser(['accounting.accounts_receivable.view'], 'ar');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/ar-aging?per_page=5')
            ->assertOk();
    }

    public function test_inventory_user_can_list_categories_for_stock_filters(): void
    {
        Organization::query()->where('company_code', 'DEMO')->update([
            'enabled_modules' => ['inventory' => true],
        ]);

        $user = $this->createRoleUser(['inventory.stock.view'], 'stockcat');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/categories?per_page=50')
            ->assertOk();
    }

    public function test_hr_leave_permission_can_load_leave_balance_and_catalog(): void
    {
        Organization::query()->where('company_code', 'DEMO')->update([
            'enabled_modules' => [
                'hr_payroll' => true,
                'hr_payroll.reports' => true,
            ],
        ]);

        $user = $this->createRoleUser(['hr.leave.view'], 'hrleave');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/')
            ->assertOk()
            ->assertJsonPath('hr.0.key', 'leave-balance');

        $this->getJson('/api/v1/reports/leave-balance?per_page=5')
            ->assertOk();
    }
}
