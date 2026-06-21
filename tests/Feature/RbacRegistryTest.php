<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class RbacRegistryTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_all_capability_codes_exist_in_permissions_table(): void
    {
        PermissionMatrixService::ensure();

        $expected = array_keys(config('permissions', []));
        $actual = Permission::query()
            ->whereIn('permission_code', $expected)
            ->pluck('permission_code')
            ->all();

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_all_permission_aliases_map_to_registry_codes(): void
    {
        PermissionMatrixService::ensure();

        $registry = PermissionMatrixService::allRegistryCodes();

        foreach (config('permission_aliases', []) as $capability => $aliases) {
            foreach ($aliases as $alias) {
                $this->assertContains(
                    $alias,
                    $registry,
                    "Alias {$alias} for capability {$capability} is not defined in permission_registry.php",
                );
            }
        }
    }

    public function test_accounting_view_grants_access_to_gl_report_without_reports_view(): void
    {
        PermissionMatrixService::ensure();
        $admin = User::where('username', 'admin')->firstOrFail();

        $role = Role::create([
            'role_name' => 'Accountant Readonly',
            'scope' => 'org',
            'is_active' => true,
        ]);

        $viewId = (int) Permission::where('permission_code', 'accounting.general_ledger.view')->value('id');
        $this->assertNotNull($viewId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $viewId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'acct_readonly_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Accountant Readonly',
            'access_scope' => 'org',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/general-ledger')
            ->assertOk();
    }

    public function test_sales_returns_view_allows_listing_customer_returns(): void
    {
        PermissionMatrixService::ensure();
        $admin = User::where('username', 'admin')->firstOrFail();

        $role = Role::create([
            'role_name' => 'Returns Viewer',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $viewId = (int) Permission::where('permission_code', 'sales.returns.view')->value('id');
        $this->assertNotNull($viewId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $viewId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'returns_viewer_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Returns Viewer',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/customer-returns')
            ->assertOk();
    }

    public function test_hr_view_grants_payroll_summary_without_reports_view(): void
    {
        PermissionMatrixService::ensure();
        $admin = User::where('username', 'admin')->firstOrFail();

        $role = Role::create([
            'role_name' => 'Payroll Clerk',
            'scope' => 'org',
            'is_active' => true,
        ]);

        $viewId = (int) Permission::where('permission_code', 'hr.payroll.view')->value('id');
        $this->assertNotNull($viewId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $viewId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'payroll_clerk_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Payroll Clerk',
            'access_scope' => 'org',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/payroll-summary')
            ->assertOk();
    }

    public function test_operations_routes_declare_erp_permission_middleware(): void
    {
        $violations = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! preg_match('#^api/v1/(sales|pos|inventory|accounting|attendance|payroll|kra|reports|ai)(/|$)#', $uri)) {
                continue;
            }

            if (str_contains($uri, '/callback')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            if (! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }
            $hasPermission = collect($middleware)->contains(
                fn (string $m) => str_starts_with($m, 'erp.permission:'),
            );

            if (! $hasPermission) {
                $violations[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $violations, 'Operations routes missing erp.permission middleware: '.implode('; ', $violations));
    }

    public function test_demo_cashier_is_branch_scoped_and_not_admin(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();

        $this->assertFalse((bool) $cashier->is_admin);
        $this->assertSame('branch', $cashier->access_scope);
    }

    public function test_demo_cashier_has_fewer_permissions_than_administrator(): void
    {
        PermissionMatrixService::ensure();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $service = app(UserPermissionService::class);

        $cashierMap = $service->permissionMapForUser($cashier);
        $adminMap = $service->permissionMapForUser($admin);

        $this->assertTrue($cashierMap['sales.create'] ?? false);
        $this->assertTrue($cashierMap['pos.till'] ?? false);
        $this->assertFalse($cashierMap['admin.manage'] ?? false);
        $this->assertFalse($cashierMap['reports.view'] ?? false);
        $this->assertFalse($cashierMap['purchasing.manage'] ?? false);
        $this->assertTrue($adminMap['admin.manage'] ?? false);

        $this->assertLessThan(
            count(array_filter($adminMap)),
            count(array_filter($cashierMap)),
        );
    }

    public function test_demo_cashier_cannot_access_admin_or_report_endpoints(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/roles')->assertForbidden();
        $this->getJson('/api/v1/reports/profit-loss')->assertForbidden();
        $this->getJson('/api/v1/erp/settings/sales')->assertForbidden();
    }

    public function test_demo_cashier_can_access_pos_sales_endpoints(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        Sanctum::actingAs($cashier);

        $this->postJson('/api/v1/sales/carts', ['channel' => 'pos'])->assertCreated();
        $this->getJson('/api/v1/products?per_page=5')->assertOk();
        $this->getJson('/api/v1/tills?per_page=5')->assertOk();
        $this->getJson('/api/v1/till-float-sessions?per_page=10&filter[status]=open&filter[cashier_id]='.$cashier->id)
            ->assertOk();
    }

    public function test_demo_cashier_only_has_external_pos_workspace(): void
    {
        PermissionMatrixService::ensure();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($cashier);
        $workspaces = app(\App\Services\Erp\WorkspaceResolver::class)->availableForUser($cashier, $gate);

        $this->assertSame(['pos'], array_column($workspaces, 'id'));
        $this->assertSame('/pos', $workspaces[0]['home_path'] ?? null);
    }

    public function test_demo_admin_backoffice_workspace_lands_on_business_summary(): void
    {
        PermissionMatrixService::ensure();
        $admin = User::where('username', 'admin')->firstOrFail();
        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($admin);
        $workspaces = app(\App\Services\Erp\WorkspaceResolver::class)->availableForUser($admin, $gate);

        $backoffice = collect($workspaces)->firstWhere('id', 'backoffice');
        $this->assertNotNull($backoffice);
        $this->assertSame('/dashboard', $backoffice['home_path'] ?? null);
    }

    public function test_demo_admin_has_distribution_workspace_when_module_enabled(): void
    {
        PermissionMatrixService::ensure();
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['distribution'] = true;
        $modules['distribution.dashboard'] = true;
        $modules['distribution.reports'] = true;
        $settings = $org->module_settings ?? [];
        $settings['distribution'] = array_merge($settings['distribution'] ?? [], [
            'enable_distribution_ops' => false,
        ]);
        $org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);

        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($admin);
        $this->assertTrue($gate->distributionOpsEnabled());

        $caps = $gate->toArray();
        $this->assertTrue($caps['distribution_ops_enabled']);
        $this->assertTrue($caps['module_settings']['distribution']['enable_distribution_ops']);

        $workspaces = app(\App\Services\Erp\WorkspaceResolver::class)->availableForUser($admin, $gate);

        $distribution = collect($workspaces)->firstWhere('id', 'distribution');
        $this->assertNotNull($distribution);
        $this->assertSame('/fulfillment', $distribution['home_path'] ?? null);
    }
}
