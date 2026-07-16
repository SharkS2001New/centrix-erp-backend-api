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
use App\Services\Organization\OrganizationReferenceDataService;
use App\Services\OrganizationPlatformConfigService;
use App\Services\Auth\RoleTemplateService;
use App\Services\Auth\UserLoginChannelPolicy;
use App\Services\Auth\UserLoginChannelService;
use App\Services\Auth\UserMobileLoginValidator;
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
                'module_settings' => $this->defaultModuleSettingsForProfile((string) $data['deployment_profile']),
            ]);

            $org = $this->syncModuleSettingsFromEnabledModules($org);

            $branchType = match ($data['deployment_profile']) {
                'small_shop' => 'small_shop',
                'distribution' => 'distribution',
                'supermarket', 'wholesale_retail' => 'supermarket',
                'custom' => 'retail',
                default => 'retail',
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
                'login_channels' => $this->profileLoginChannels((string) $data['deployment_profile']),
                'is_active' => true,
                'must_change_password' => false,
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
            app(OrganizationReferenceDataService::class)->seedForOrganization((int) $org->id);

            app(RoleTemplateService::class)->ensureAllRoles();

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
     * @return array<string, mixed>
     */
    protected function defaultModuleSettingsForProfile(string $profile): array
    {
        $security = \App\Services\Auth\SecuritySettingsResolver::normalize(
            config('erp.module_settings_defaults.security', []),
        );

        $settings = [
            'security' => $security,
            'distribution' => [],
            'inventory' => ['reserve_stock_on_cart' => true, 'default_pos_sale_location' => 'shop', 'cart_reservation_ttl_minutes' => 15],
        ];

        if ($profile === 'supermarket') {
            $settings['sales'] = [
                'enable_barcode_scanner' => true,
                'allow_sell_from_shop' => true,
                'allow_sell_from_store' => false,
                'enable_retail_pricing' => true,
            ];
        }

        return $settings;
    }

    /**
     * @param  list<string>  $configChannels
     * @return list<string>
     */
    public function mapConfigChannelsToLoginChannels(array $configChannels): array
    {
        $map = ['backend' => 'backoffice', 'pos' => 'pos', 'mobile' => 'mobile'];

        return array_values(array_unique(array_map(
            fn (string $channel) => $map[$channel] ?? $channel,
            $configChannels,
        )));
    }

    /**
     * Sales order channels exposed in capabilities (pos only when external POS is enabled).
     *
     * @param  array<string, bool>  $modules
     * @return list<string>
     */
    public function salesChannelsFromEnabledModules(array $modules, bool $mobileOrdersEnabled = true): array
    {
        $channels = [];
        if ($modules['sales.pos'] ?? false) {
            $channels[] = 'pos';
        }
        if (($modules['sales.mobile'] ?? false) && $mobileOrdersEnabled) {
            $channels[] = 'mobile';
        }
        if ($modules['sales.backend'] ?? false) {
            $channels[] = 'backend';
        }

        return $channels;
    }

    /**
     * User login channels allowed for an org based on enabled modules.
     *
     * @param  array<string, bool>  $modules
     * @param  array<string, mixed>|null  $salesPlatform
     * @return list<string>
     */
    public function loginChannelsFromEnabledModules(array $modules, ?array $salesPlatform = null): array
    {
        $mobileOrdersEnabled = ($salesPlatform['enable_mobile_orders'] ?? true) !== false;
        $channels = [];

        if ($modules['sales.backend'] ?? false) {
            $channels[] = 'backoffice';
        }
        if ($modules['sales.pos'] ?? false) {
            $channels[] = 'pos';
        }
        if (($modules['sales.mobile'] ?? false) && $mobileOrdersEnabled) {
            $channels[] = 'mobile';
        }
        if (($modules['sales.backend'] ?? false) && ($salesPlatform['enable_manager_app'] ?? true) !== false) {
            $channels[] = 'manager';
        }

        return $channels !== [] ? $channels : ['backoffice'];
    }

    /**
     * @return list<string>
     */
    protected function profileLoginChannels(string $profile): array
    {
        $configChannels = config("erp.profiles.{$profile}.default_channels", ['backend']);

        return $this->mapConfigChannelsToLoginChannels($configChannels);
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
        $input = [];
        foreach ($moduleKeys as $key) {
            if (array_key_exists($key, $enabledModules)) {
                $input[$key] = (bool) $enabledModules[$key];
            }
        }

        $cascaded = ModuleRegistry::cascade($input);
        $sparse = [];

        foreach ($cascaded as $key => $value) {
            if ($value) {
                $sparse[$key] = true;
            }
        }

        // Full module maps (legacy provisioning) can persist false report bundles even when
        // the parent domain is on. Only honor explicit report disables from sparse maps.
        $isSparseMap = count($input) < (int) (count($moduleKeys) / 2);
        if ($isSparseMap) {
            foreach (ModuleRegistry::reportModuleKeys() as $reportKey) {
                if (! array_key_exists($reportKey, $input) || ($input[$reportKey] ?? true)) {
                    continue;
                }

                $parent = ModuleRegistry::parentKey($reportKey);
                if ($parent !== null && ($cascaded[$parent] ?? false)) {
                    $sparse[$reportKey] = false;
                }
            }
        }

        return $sparse === [] ? null : $sparse;
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

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOrganizationUser(Organization $org, array $data): User
    {
        return DB::transaction(function () use ($org, $data) {
            $branch = Branch::query()
                ->where('organization_id', $org->id)
                ->orderByRaw("CASE WHEN branch_code = 'HQ' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first();

            if (! $branch) {
                throw new \RuntimeException('Organization has no branch. Register the organization first.');
            }

            $isAdmin = (bool) ($data['is_admin'] ?? false);
            $role = $this->resolveOrganizationUserRole($isAdmin);

            $channels = app(UserLoginChannelPolicy::class)->sanitizeForOrganization(
                $org,
                $data['login_channels'] ?? app(UserLoginChannelPolicy::class)->defaultChannelsForOrganization($org),
            );

            app(UserMobileLoginValidator::class)->assertMobileChannelAllowedForUser(
                $org,
                $channels,
                (int) $role->id,
                $isAdmin,
            );

            return User::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'role_id' => $role->id,
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'full_name' => $data['full_name'],
                'is_admin' => $isAdmin ? 1 : 0,
                'is_super_admin' => 0,
                'access_scope' => 'org',
                'login_channels' => $channels,
                'is_mobile_user' => app(UserLoginChannelService::class)->syncLegacyMobileFlag($channels),
                'is_active' => true,
                'must_change_password' => (bool) ($data['must_change_password'] ?? true),
            ]);
        });
    }

    protected function resolveOrganizationUserRole(bool $isAdmin): Role
    {
        if ($isAdmin) {
            $role = Role::where('role_name', 'Administrator')->where('scope', 'org')->first();
            if ($role) {
                return $role;
            }
        }

        $role = Role::where('role_name', 'Branch Manager')->where('scope', 'org')->first()
            ?? Role::where('role_name', 'Cashier')->where('scope', 'branch')->first();

        if (! $role) {
            throw new \RuntimeException('No suitable organization role found. Seed roles first.');
        }

        return $role;
    }
}
