<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Erp\PermissionMatrixService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PermissionMatrixSidebarCoverageTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function seedLicense(User $user): void
    {
        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
    }

    public function test_inventory_analytics_appears_when_only_parent_inventory_module_is_on(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($admin);

        $org = Organization::findOrFail($admin->organization_id);
        $org->update([
            'enabled_modules' => [
                'admin' => true,
                'inventory' => true,
                // Explicitly off — sidebar still cascades from parent inventory.
                'inventory.dashboard' => false,
                'sales.dashboard' => false,
            ],
        ]);

        $gate = app(ErpContext::class)->gateForOrganization($org->fresh());
        $this->assertTrue($gate->enabled('inventory'));
        $this->assertFalse($gate->enabled('inventory.dashboard'));

        $apps = PermissionMatrixService::applicationsGroupedForUi($gate);
        $dashboard = collect($apps)
            ->flatMap(fn (array $app) => $app['modules'])
            ->firstWhere('module', 'dashboard');

        $this->assertNotNull($dashboard, 'Dashboard group should appear when inventory parent is enabled');
        $labels = collect($dashboard['features'])->pluck('label')->all();
        $this->assertContains('Inventory analytics', $labels);
        $this->assertContains('Business summary', $labels);
    }

    public function test_permission_matrix_includes_sidebar_report_and_notification_permissions(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($admin);
        Sanctum::actingAs($admin);

        PermissionMatrixService::ensure();

        $res = $this->getJson('/api/v1/roles/permissions/matrix')->assertOk();
        $codes = collect($res->json('applications'))
            ->flatMap(fn (array $app) => $app['modules'] ?? [])
            ->flatMap(fn (array $module) => $module['features'] ?? [])
            ->flatMap(fn (array $feature) => $feature['permissions'] ?? [])
            ->pluck('permission_code');

        $this->assertTrue($codes->contains('dashboard.inventory.view'));
        $this->assertTrue($codes->contains('reports.sales_by_product.view'));
        $this->assertTrue($codes->contains('reports.low_stock.view'));
        $this->assertTrue($codes->contains('reports.payroll_summary.view'));
        $this->assertTrue($codes->contains('reports.profit_loss.view'));
        $this->assertTrue($codes->contains('reports.price_list.view'));
        $this->assertTrue($codes->contains('admin.notifications.view'));
        $this->assertTrue($codes->contains('sales.collect_payment.create'));
        $this->assertTrue($codes->contains('admin.kra_responses.view'));
        $this->assertTrue($codes->contains('admin.attendance_clock.view'));
        $this->assertTrue($codes->contains('admin.attendance_clock.create'));

        $apps = collect($res->json('applications'));
        $adminFeatures = collect($apps->firstWhere('id', 'admin')['modules'] ?? [])
            ->firstWhere('module', 'admin');
        $this->assertNotNull($adminFeatures);
        $adminKeys = collect($adminFeatures['features'] ?? [])->pluck('key')->all();
        $this->assertContains('attendance_clock', $adminKeys);
        $this->assertContains('kra_responses', $adminKeys);

        $backofficePricingFeatures = collect($apps->firstWhere('id', 'backoffice')['modules'] ?? [])
            ->firstWhere('module', 'pricing_tax');
        $this->assertNotNull($backofficePricingFeatures);
        $this->assertNotContains(
            'kra_responses',
            collect($backofficePricingFeatures['features'] ?? [])->pluck('key')->all(),
            'KRA device logs belong under Administration, not Pricing & tax',
        );

        $backofficeSalesFeatures = collect($apps->firstWhere('id', 'backoffice')['modules'] ?? [])
            ->firstWhere('module', 'sales');
        $this->assertNotNull($backofficeSalesFeatures);
        $this->assertContains(
            'collect_payment',
            collect($backofficeSalesFeatures['features'] ?? [])->pluck('key')->all(),
        );

        $backofficePaymentFeatures = collect($apps->firstWhere('id', 'backoffice')['modules'] ?? [])
            ->firstWhere('module', 'payments');
        $this->assertNotNull($backofficePaymentFeatures);
        $this->assertSame(
            ['sale_payments'],
            collect($backofficePaymentFeatures['features'] ?? [])->pluck('key')->all(),
        );
        $featureKeysByApp = $apps->mapWithKeys(function (array $app) {
            $keys = collect($app['modules'] ?? [])
                ->filter(fn (array $module) => ($module['module'] ?? '') === 'reports')
                ->flatMap(fn (array $module) => $module['features'] ?? [])
                ->pluck('key')
                ->all();

            return [$app['id'] => $keys];
        });

        $hrFeatureKeys = collect($apps->firstWhere('id', 'hr')['modules'] ?? [])
            ->firstWhere('module', 'hr');
        $this->assertNotNull($hrFeatureKeys);
        $this->assertContains('manual_attendance', collect($hrFeatureKeys['features'] ?? [])->pluck('key')->all());

        $this->assertContains('payroll_summary', $featureKeysByApp->get('hr', []));
        $this->assertNotContains('payroll_summary', $featureKeysByApp->get('backoffice', []));
        $this->assertContains('profit_loss', $featureKeysByApp->get('accounting', []));
        $this->assertNotContains('profit_loss', $featureKeysByApp->get('backoffice', []));
        $this->assertContains('price_list', $featureKeysByApp->get('backoffice', []));
        $this->assertContains('daily_sales', $featureKeysByApp->get('manager', []));
        $this->assertContains('profit_loss', $featureKeysByApp->get('manager', []));

        $backofficePricingFeatures = collect($apps->firstWhere('id', 'backoffice')['modules'] ?? [])
            ->firstWhere('module', 'pricing_tax');
        $this->assertNotNull($backofficePricingFeatures);
        $this->assertNotContains(
            'price_list',
            collect($backofficePricingFeatures['features'] ?? [])->pluck('key')->all(),
            'Price list is a report permission (reports.price_list.view), not pricing_tax',
        );
    }

    public function test_attendance_view_roles_receive_new_time_attendance_pages(): void
    {
        PermissionMatrixService::ensure();

        $role = \App\Models\Role::create([
            'role_name' => 'Attendance Clerk '.uniqid(),
            'scope' => 'org',
            'is_active' => true,
        ]);

        $attendanceId = (int) \App\Models\Permission::where('permission_code', 'hr.attendance.view')->value('id');
        $this->assertGreaterThan(0, $attendanceId);

        \Illuminate\Support\Facades\DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $attendanceId,
        ]);

        PermissionMatrixService::ensureHrTimeAttendancePagesForExistingRoles();

        $granted = \Illuminate\Support\Facades\DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->pluck('permission_id');

        foreach ([
            'hr.attendance_history.view',
            'hr.missed_punches.view',
            'hr.duplicate_punches.view',
            'hr.absents.view',
            'hr.lateness.view',
        ] as $code) {
            $id = (int) \App\Models\Permission::where('permission_code', $code)->value('id');
            $this->assertTrue($granted->contains($id), "Expected {$code} granted to attendance.view roles");
        }
    }

    public function test_every_registry_report_feature_appears_in_exactly_one_application(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($admin);
        Sanctum::actingAs($admin);

        PermissionMatrixService::ensure();

        $registryReportFeatures = array_keys(config('permission_registry.groups.reports.features', []));
        $this->assertNotEmpty($registryReportFeatures);

        $apps = collect($this->getJson('/api/v1/roles/permissions/matrix')->assertOk()->json('applications'));
        $placements = [];
        foreach ($apps as $app) {
            foreach ($app['modules'] ?? [] as $module) {
                if (($module['module'] ?? '') !== 'reports') {
                    continue;
                }
                foreach ($module['features'] ?? [] as $feature) {
                    $key = (string) ($feature['key'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    $placements[$key][] = (string) $app['id'];
                }
            }
        }

        foreach ($registryReportFeatures as $feature) {
            $appsForFeature = $placements[$feature] ?? [];
            // Manager application may mirror a curated subset for Field Manager roles.
            $nonManagerApps = array_values(array_filter(
                $appsForFeature,
                static fn (string $id): bool => $id !== 'manager',
            ));
            $this->assertNotEmpty(
                $nonManagerApps,
                "Report feature [{$feature}] should appear in a primary application (got: ".implode(',', $appsForFeature).')',
            );
            $this->assertCount(
                1,
                $nonManagerApps,
                "Report feature [{$feature}] should appear in exactly one primary application, got: ".implode(',', $nonManagerApps),
            );
        }
    }

    public function test_hr_view_capability_unlocks_time_attendance_nav_codes(): void
    {
        PermissionMatrixService::ensure();
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($admin);

        $role = \App\Models\Role::create([
            'role_name' => 'HR Viewer '.uniqid(),
            'scope' => 'org',
            'is_active' => true,
        ]);
        $hrViewId = (int) \App\Models\Permission::where('permission_code', 'hr.view')->value('id');
        $this->assertGreaterThan(0, $hrViewId);
        \Illuminate\Support\Facades\DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $hrViewId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'hr_viewer_'.uniqid(),
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'full_name' => 'HR Viewer',
            'access_scope' => 'org',
            'is_active' => true,
            'is_admin' => false,
        ]);

        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($user);
        $nav = app(\App\Services\Auth\UserPermissionService::class)
            ->navigationPermissionMapForUser($user, $gate);

        foreach ([
            'hr.absents.view',
            'hr.missed_punches.view',
            'hr.duplicate_punches.view',
            'hr.attendance_history.view',
            'hr.lateness.view',
            'hr.pending_overtime.view',
            'hr.shifts.view',
        ] as $code) {
            $this->assertTrue($nav[$code] ?? false, "Expected {$code} when hr.view is assigned");
        }
    }

    public function test_single_hr_leaf_does_not_unlock_sibling_time_attendance_pages(): void
    {
        PermissionMatrixService::ensure();
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($admin);

        $role = \App\Models\Role::create([
            'role_name' => 'Employees Only '.uniqid(),
            'scope' => 'org',
            'is_active' => true,
        ]);
        $empViewId = (int) \App\Models\Permission::where('permission_code', 'hr.employees.view')->value('id');
        $this->assertGreaterThan(0, $empViewId);
        \Illuminate\Support\Facades\DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $empViewId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'hr_emp_only_'.uniqid(),
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'full_name' => 'Employees Only',
            'access_scope' => 'org',
            'is_active' => true,
            'is_admin' => false,
        ]);

        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($user);
        $nav = app(\App\Services\Auth\UserPermissionService::class)
            ->navigationPermissionMapForUser($user, $gate);

        $this->assertTrue($nav['hr.employees.view'] ?? false);
        $this->assertFalse($nav['hr.absents.view'] ?? false);
        $this->assertFalse($nav['hr.missed_punches.view'] ?? false);
    }
}
