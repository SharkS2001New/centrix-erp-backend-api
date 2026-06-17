<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\StandardChartOfAccounts;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ModuleRegistry;
use App\Services\OrganizationPlatformConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OrganizationProvisioningService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{organization: Organization, manager: User, branch: Branch}
     */
    public function provision(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $org = Organization::create([
                'company_code' => strtoupper($data['company_code']),
                'org_name' => $data['org_name'],
                'org_email' => $data['org_email'],
                'primary_tel' => $data['primary_tel'],
                'org_address' => $data['org_address'],
                'org_pin' => $data['org_pin'] ?? null,
                'vat_regno' => $data['vat_regno'] ?? null,
                'deployment_profile' => $data['deployment_profile'],
                'enabled_modules' => $this->normalizeEnabledModules($data['enabled_modules'] ?? null),
                'module_settings' => [
                    'distribution' => [],
                    'inventory' => ['reserve_stock_on_cart' => true, 'default_pos_sale_location' => 'shop'],
                ],
            ]);

            $org = $this->syncModuleSettingsFromEnabledModules($org);

            $branchType = match ($data['deployment_profile']) {
                'small_shop' => 'small_shop',
                'distribution' => 'distribution',
                default => 'supermarket',
            };

            $branch = Branch::create([
                'organization_id' => $org->id,
                'branch_code' => 'HQ',
                'branch_name' => 'Head Office',
                'branch_type' => $branchType,
                'branch_phone' => $data['primary_tel'],
                'branch_address' => $data['org_address'],
                'settings' => ['stock_alert_mode' => 'both', 'global_low_stock_threshold' => 5],
            ]);

            $role = Role::where('role_name', 'Administrator')->where('scope', 'org')->first();
            if (! $role) {
                $role = Role::create([
                    'role_name' => 'Administrator',
                    'scope' => 'org',
                    'is_active' => true,
                ]);

                foreach (Permission::all() as $perm) {
                    DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $role->id,
                        'permission_id' => $perm->id,
                    ]);
                }
            }

            $manager = User::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'role_id' => $role->id,
                'username' => $data['admin_username'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'full_name' => $data['admin_full_name'],
                'is_admin' => 1,
                'is_super_admin' => 0,
                'access_scope' => 'org',
                'login_channels' => ['backoffice', 'pos', 'mobile'],
                'is_active' => true,
            ]);

            $adminDept = Department::create([
                'organization_id' => $org->id,
                'department_code' => 'ADMIN',
                'department_name' => 'Administration',
                'is_active' => true,
            ]);

            $adminPos = Position::create([
                'organization_id' => $org->id,
                'position_code' => 'ADMIN',
                'position_title' => 'Administrator',
                'is_active' => true,
            ]);

            Employee::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'department_id' => $adminDept->id,
                'position_id' => $adminPos->id,
                'user_id' => $manager->id,
                'employee_code' => 'EMP#0001',
                'payroll_number' => 'EMP#0001',
                'first_name' => $data['admin_full_name'],
                'last_name' => 'Admin',
                'full_name' => $data['admin_full_name'],
                'email' => $data['admin_email'],
                'phone' => $data['primary_tel'],
                'job_title' => 'Administrator',
                'employment_status' => 'active',
                'employment_type' => 'permanent',
                'pay_frequency' => 'monthly',
                'hire_date' => now()->toDateString(),
                'base_salary' => 50000,
                'country' => 'Kenya',
                'is_active' => true,
            ]);

            $this->seedAccountingFoundation($org);

            if (! empty($data['sales_platform']) && is_array($data['sales_platform'])) {
                $org = app(OrganizationPlatformConfigService::class)->applySalesPlatformConfig($org, $data['sales_platform']);
            } else {
                $org = app(OrganizationPlatformConfigService::class)->applySalesPlatformConfig(
                    $org,
                    app(OrganizationPlatformConfigService::class)->defaultSalesPlatformConfig(
                        (string) ($data['deployment_profile'] ?? 'wholesale_retail'),
                    ),
                );
            }

            return [
                'organization' => $org,
                'manager' => $manager,
                'branch' => $branch,
            ];
        });
    }

    /**
     * @param  array<string, bool>|null  $enabledModules
     * @return array<string, bool>|null
     */
    public function normalizeEnabledModules(?array $enabledModules): ?array
    {
        if ($enabledModules === null) {
            return null;
        }

        $moduleKeys = ModuleRegistry::keys();
        $normalized = [];
        foreach ($moduleKeys as $key) {
            if (array_key_exists($key, $enabledModules)) {
                $normalized[$key] = (bool) $enabledModules[$key];
            }
        }

        $normalized = ModuleRegistry::cascade($normalized);

        return $normalized === [] ? null : $normalized;
    }

    public function syncModuleSettingsFromEnabledModules(Organization $org): Organization
    {
        $gate = app(CapabilityGate::class)->forOrganization($org);
        $modules = $gate->allModules();
        $settings = $org->module_settings ?? [];
        $distributionEnabled = (bool) ($modules['distribution'] ?? false);

        $dist = is_array($settings['distribution'] ?? null) ? $settings['distribution'] : [];
        $dist['enable_distribution_ops'] = $distributionEnabled;
        if ($distributionEnabled) {
            $dist['inherit_customer_route'] = $dist['inherit_customer_route'] ?? true;
            $dist['auto_assign_truck'] = $dist['auto_assign_truck'] ?? true;
            $dist['auto_assign_driver'] = $dist['auto_assign_driver'] ?? true;
        }
        $settings['distribution'] = $dist;

        $org->forceFill(['module_settings' => $settings])->save();

        return $org->fresh();
    }

    protected function seedAccountingFoundation(Organization $org): void
    {
        $gate = app(CapabilityGate::class)->forOrganization($org);
        if (! $gate->enabled('accounting')) {
            return;
        }

        app(StandardChartOfAccounts::class)->seedForOrganization($org);
        app(FiscalPeriodService::class)->seedYear((int) $org->id, (int) now()->year);
    }
}
