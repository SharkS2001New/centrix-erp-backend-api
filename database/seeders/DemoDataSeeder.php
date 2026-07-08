<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CurrentStock;
use App\Models\Department;
use App\Models\Driver;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\PayrollDeductionType;
use App\Models\Position;
use App\Models\Expense;
use App\Models\ExpenseGroup;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\Role;
use App\Services\Erp\PermissionMatrixService;
use App\Services\Organization\OrganizationReferenceDataService;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\SystemSetting;
use App\Models\Till;
use App\Models\TillFloatSession;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Vat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('organizations')) {
            $this->command->error('Run migrations first: php artisan migrate');
            return;
        }

        $this->truncateDemoTables();

        $org = Organization::create([
            'company_code' => 'DEMO',
            'org_name' => 'Demo Wholesalers Ltd',
            'org_email' => 'admin@demo.co.ke',
            'primary_tel' => '0700111222',
            'org_address' => 'Industrial Area, Nairobi, KE',
            'org_pin' => 'P051234567Z',
            'vat_regno' => '0123456789',
            'deployment_profile' => 'wholesale_retail',
            'module_settings' => [
                'security' => [
                    'screen_lock_minutes' => 5,
                    'session_idle_minutes' => 60,
                ],
                'sales' => ['auto_assign_truck' => true, 'auto_assign_driver' => true],
                'inventory' => ['reserve_stock_on_cart' => true, 'default_pos_sale_location' => 'shop'],
                'finance' => [
                    'mpesa' => [
                        'env' => 'sandbox',
                        'consumer_key' => '',
                        'consumer_secret' => '',
                        'shortcode' => '',
                        'till_number' => '',
                        'passkey' => '',
                        'stk_callback_url' => 'https://example.com/api/v1/payments/stk/callback',
                        'c2b_confirmation_url' => 'https://example.com/api/v1/payments/c2b/confirmation',
                        'c2b_validation_url' => 'https://example.com/api/v1/payments/c2b/validation',
                    ],
                ],
            ],
        ]);

        $hq = Branch::create([
            'organization_id' => $org->id,
            'branch_code' => 'HQ',
            'branch_name' => 'Head Office',
            'branch_type' => 'supermarket',
            'branch_phone' => '0700111222',
            'settings' => ['stock_alert_mode' => 'both', 'global_low_stock_threshold' => 5],
        ]);

        app(OrganizationReferenceDataService::class)->seedForOrganization((int) $org->id);

        $rAdmin = Role::create(['role_name' => 'Administrator', 'scope' => 'org']);
        $rCash = Role::create(['role_name' => 'Cashier', 'scope' => 'branch']);

        PermissionMatrixService::ensure();

        foreach (Permission::query()->pluck('id') as $permId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $rAdmin->id,
                'permission_id' => $permId,
            ]);
        }

        $cashierPerms = [
            'pos.terminal.view',
            'pos.checkout.create',
            'pos.till_management.view',
            'pos.till_management.create',
            'pos.end_of_day.view',
        ];
        foreach ($cashierPerms as $code) {
            $pid = Permission::where('permission_code', $code)->value('id');
            if ($pid) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $rCash->id,
                    'permission_id' => $pid,
                ]);
            }
        }

        $this->call(ProductionRoleSeeder::class);

        $admin = User::create([
            'organization_id' => $org->id,
            'branch_id' => $hq->id,
            'role_id' => $rAdmin->id,
            'username' => 'admin',
            'email' => 'admin@demo.co.ke',
            'password' => Hash::make('password'),
            'full_name' => 'Demo Organization Manager',
            'is_admin' => 1,
            'is_super_admin' => 0,
            'access_scope' => 'org',
            'login_channels' => ['backoffice', 'pos', 'mobile', 'manager'],
            'is_active' => true,
        ]);

        $salesDept = Department::create([
            'organization_id' => $org->id,
            'department_code' => 'SALES',
            'department_name' => 'Sales',
            'is_active' => true,
        ]);

        $gmPosition = Position::create([
            'organization_id' => $org->id,
            'position_code' => 'GM',
            'position_title' => 'General Manager',
            'is_active' => true,
        ]);

        PayrollDeductionType::create([
            'organization_id' => $org->id,
            'deduction_code' => 'SACCO',
            'name' => 'SACCO contribution',
            'calc_type' => 'fixed',
            'default_amount' => 1500,
            'is_active' => true,
        ]);

        $adminEmployee = Employee::create([
            'organization_id' => $org->id,
            'branch_id' => $hq->id,
            'department_id' => $salesDept->id,
            'position_id' => $gmPosition->id,
            'user_id' => $admin->id,
            'employee_code' => 'EMP#0001',
            'payroll_number' => 'EMP#0001',
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'full_name' => 'System Administrator',
            'email' => 'admin@demo.co.ke',
            'phone' => '0700111222',
            'job_title' => 'General Manager',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => now()->subYears(2)->toDateString(),
            'base_salary' => 85000,
            'kra_pin' => 'A001234567Z',
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        EmployeeBankAccount::create([
            'employee_id' => $adminEmployee->id,
            'bank_name' => 'KCB Bank',
            'bank_branch' => 'Westlands',
            'account_number' => '1234567890',
            'account_name' => 'System Administrator',
            'payment_method' => 'bank_transfer',
            'is_primary' => true,
        ]);

        $cashier = User::create([
            'organization_id' => $org->id,
            'branch_id' => $hq->id,
            'role_id' => $rCash->id,
            'username' => 'cashier',
            'password' => Hash::make('password'),
            'full_name' => 'Peter Otieno',
            'is_admin' => false,
            'is_super_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['pos', 'backoffice'],
            'is_active' => true,
        ]);

        $vatStd = Vat::where('vat_code', 'V')->where('organization_id', $org->id)->first()
            ?? Vat::create([
                'vat_code' => 'V',
                'vat_name' => 'Standard Rated',
                'vat_percentage' => 16,
                'organization_id' => $org->id,
                'created_by' => $admin->id,
            ]);

        $uomKg = Uom::create([
            'conversion_factor' => 1, 'full_name' => 'Kilogram', 'uom_type' => 'kg',
            'is_base_unit' => true, 'organization_id' => $org->id, 'created_by' => $admin->id,
        ]);

        $sup = Supplier::create([
            'supplier_code' => 'SUP-001',
            'supplier_name' => 'Mumias Sugar Co.',
            'phone' => '0711222333',
            'organization_id' => $org->id,
            'contacts' => [['label' => 'Accounts', 'phone' => '0711999000', 'email' => 'accounts@mumias.co.ke']],
            'created_by' => $admin->id,
        ]);

        $cat = Category::create(['category_name' => 'Food & Beverage', 'organization_id' => $org->id, 'created_by' => $admin->id]);
        $sub = SubCategory::create([
            'category_id' => $cat->id,
            'subcategory_name' => 'Sugar',
            'organization_id' => $org->id,
            'created_by' => $admin->id,
        ]);

        $sugar = Product::create([
            'product_code' => '6161100100015',
            'product_name' => 'Mumias White Sugar 50kg',
            'subcategory_id' => $sub->id,
            'unit_id' => $uomKg->id,
            'unit_price' => 6000,
            'stock_in_shop' => 2500,
            'stock_in_store' => 5000,
            'supplier_id' => $sup->id,
            'sell_on_retail' => 1,
            'vat_id' => $vatStd->id,
            'organization_id' => $org->id,
            'reorder_point' => 500,
            'low_stock_alert_enabled' => true,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        RetailPackageSetting::create([
            'product_code' => $sugar->product_code,
            'max_qty_measure' => 50,
            'markup_price' => 5,
            'min_uom_measure' => 'kg',
            'wholesale_markup_price' => 10,
            'max_uom_measure' => 'bag',
        ]);

        \App\Models\PriceHistory::insert([
            [
                'product_code' => $sugar->product_code,
                'unit_price' => 5500,
                'cost_price' => 4800,
                'discount_pct' => 0,
                'changed_by' => $admin->id,
                'organization_id' => $org->id,
                'changed_at' => now()->subDays(30),
            ],
            [
                'product_code' => $sugar->product_code,
                'unit_price' => 5750,
                'cost_price' => 4900,
                'discount_pct' => 0,
                'changed_by' => $admin->id,
                'organization_id' => $org->id,
                'changed_at' => now()->subDays(14),
            ],
            [
                'product_code' => $sugar->product_code,
                'unit_price' => 6000,
                'cost_price' => 5100,
                'discount_pct' => 0,
                'changed_by' => $admin->id,
                'organization_id' => $org->id,
                'changed_at' => now()->subDays(2),
            ],
        ]);

        CurrentStock::create([
            'product_code' => $sugar->product_code,
            'branch_id' => $hq->id,
            'shop_quantity' => 2500,
            'store_quantity' => 5000,
        ]);

        $route = RouteModel::create([
            'organization_id' => $org->id,
            'route_name' => 'Nairobi East',
            'route_markup_price' => 10,
            'direction' => 'East',
        ]);

        $vehicle = Vehicle::create([
            'branch_id' => $hq->id,
            'vehicle_code' => 'KCA-123A',
            'vehicle_name' => 'Delivery Van 1',
            'plate_number' => 'KCA 123A',
            'is_active' => true,
        ]);

        $driver = Driver::create([
            'branch_id' => $hq->id,
            'default_vehicle_id' => $vehicle->id,
            'default_route_id' => $route->id,
            'driver_code' => 'JK-001',
            'full_name' => 'John Kamau',
            'phone' => '0712345678',
            'is_active' => true,
        ]);

        Customer::create([
            'customer_num' => 5002,
            'branch_id' => $hq->id,
            'organization_id' => $org->id,
            'customer_name' => 'Eastlands Mini Mart',
            'customer_type' => 'route',
            'phone_number' => '0722111222',
            'route_id' => $route->id,
            'created_by' => $admin->id,
            'credit_limit' => 50000,
        ]);

        $till = Till::create([
            'branch_id' => $hq->id,
            'till_number' => 'TILL-01',
            'cashier_id' => $admin->id,
        ]);

        $session = TillFloatSession::create([
            'till_id' => $till->id,
            'branch_id' => $hq->id,
            'cashier_id' => $admin->id,
            'session_date' => date('Y-m-d'),
            'working_amount' => 5000,
            'float_breakdown' => ['CASH' => 5000],
            'status' => 'open',
        ]);

        $sale = Sale::create([
            'order_num' => 1,
            'branch_id' => $hq->id,
            'organization_id' => $org->id,
            'channel' => 'pos',
            'till_id' => $till->id,
            'float_session_id' => $session->id,
            'cashier_id' => $admin->id,
            'customer_name_override' => 'Walk-in Customer',
            'status' => 'completed',
            'total_vat' => 0,
            'order_total' => 1250,
            'cash' => 1250,
            'payment_method_code' => 'CASH',
            'payment_status' => 'paid',
            'amount_paid' => 1250,
            'completed_at' => now(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => $sugar->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => 10,
            'uom' => 'kg',
            'selling_price' => 125,
            'amount' => 1250,
            'on_wholesale_retail' => 1,
        ]);

        $completedDelivery = Sale::create([
            'order_num' => 2,
            'branch_id' => $hq->id,
            'organization_id' => $org->id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_num' => 5002,
            'route_id' => $route->id,
            'status' => 'completed',
            'total_vat' => 0,
            'order_total' => 2500,
            'payment_method_code' => 'CASH',
            'payment_status' => 'paid',
            'amount_paid' => 2500,
            'fulfillment_meta' => [
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
            ],
            'completed_at' => now(),
        ]);

        SaleItem::create([
            'sale_id' => $completedDelivery->id,
            'product_code' => $sugar->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => 20,
            'uom' => 'kg',
            'selling_price' => 125,
            'amount' => 2500,
            'on_wholesale_retail' => 1,
        ]);

        $pendingDelivery = Sale::create([
            'order_num' => 3,
            'branch_id' => $hq->id,
            'organization_id' => $org->id,
            'channel' => 'mobile',
            'cashier_id' => $admin->id,
            'customer_num' => 5002,
            'route_id' => $route->id,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 1250,
            'payment_method_code' => 'CASH',
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'fulfillment_meta' => [
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
            ],
        ]);

        SaleItem::create([
            'sale_id' => $pendingDelivery->id,
            'product_code' => $sugar->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => 10,
            'uom' => 'kg',
            'selling_price' => 125,
            'amount' => 1250,
            'on_wholesale_retail' => 1,
        ]);

        SystemSetting::firstOrCreate(
            ['id' => 1],
            ['organization_id' => $org->id, 'allow_below_stock' => 0, 'stock_alert_mode' => 'per_product']
        );

        AuditLog::insert([
            [
                'user_id' => $admin->id,
                'organization_id' => $org->id,
                'branch_id' => $hq->id,
                'action' => 'create',
                'table_name' => 'products',
                'record_id' => (string) $sugar->product_code,
                'old_values' => null,
                'new_values' => json_encode(['product_name' => $sugar->product_name, 'selling_price' => 125]),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'DemoDataSeeder',
                'created_at' => now()->subDays(3),
            ],
            [
                'user_id' => $admin->id,
                'organization_id' => $org->id,
                'branch_id' => $hq->id,
                'action' => 'update',
                'table_name' => 'products',
                'record_id' => (string) $sugar->product_code,
                'old_values' => json_encode(['selling_price' => 120]),
                'new_values' => json_encode(['selling_price' => 125]),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'DemoDataSeeder',
                'created_at' => now()->subDays(2),
            ],
            [
                'user_id' => $cashier->id,
                'organization_id' => $org->id,
                'branch_id' => $hq->id,
                'action' => 'create',
                'table_name' => 'sales',
                'record_id' => (string) $sale->id,
                'old_values' => null,
                'new_values' => json_encode(['order_num' => $sale->order_num, 'order_total' => 1250, 'status' => 'completed']),
                'ip_address' => '10.0.0.12',
                'user_agent' => 'POS Terminal',
                'created_at' => now()->subDay(),
            ],
            [
                'user_id' => $admin->id,
                'organization_id' => $org->id,
                'branch_id' => $hq->id,
                'action' => 'update',
                'table_name' => 'users',
                'record_id' => (string) $cashier->id,
                'old_values' => json_encode(['is_active' => false]),
                'new_values' => json_encode(['is_active' => true]),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0',
                'created_at' => now()->subHours(6),
            ],
        ]);

        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '1000'],
            ['account_name' => 'Cash', 'account_type' => 'asset', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '1100'],
            ['account_name' => 'Bank Account', 'account_type' => 'asset', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '1200'],
            ['account_name' => 'Accounts Receivable', 'account_type' => 'asset', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '1300'],
            ['account_name' => 'Inventory', 'account_type' => 'asset', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '2100'],
            ['account_name' => 'VAT Payable', 'account_type' => 'liability', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '2000'],
            ['account_name' => 'Accounts Payable', 'account_type' => 'liability', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '3000'],
            ['account_name' => 'Owner Equity', 'account_type' => 'equity', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '3100'],
            ['account_name' => 'Retained Earnings', 'account_type' => 'equity', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '4000'],
            ['account_name' => 'Sales Revenue', 'account_type' => 'revenue', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '5000'],
            ['account_name' => 'Cost of Goods Sold', 'account_type' => 'expense', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '5100'],
            ['account_name' => 'Cash Over / Short', 'account_type' => 'expense', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '5200'],
            ['account_name' => 'Payroll Expense', 'account_type' => 'expense', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '5300'],
            ['account_name' => 'Operating Expenses', 'account_type' => 'expense', 'is_active' => true]
        );

        $platformCode = config('erp.platform_company_code', 'PLATFORM');
        $platformEmail = config('erp.platform_super_admin_email');
        if (! is_string($platformEmail) || trim($platformEmail) === '') {
            $platformEmail = app()->environment('local', 'testing') ? 'platform-admin@example.test' : null;
        } else {
            $platformEmail = trim($platformEmail);
        }
        if ($platformEmail === null) {
            throw new \RuntimeException(
                'Set PLATFORM_SUPER_ADMIN_EMAIL in .env before seeding the platform super admin.',
            );
        }
        $platformPassword = config('erp.platform_super_admin_password');
        if (! is_string($platformPassword) || $platformPassword === '') {
            $platformPassword = app()->environment('local', 'testing') ? 'password' : null;
        }
        if ($platformPassword === null) {
            throw new \RuntimeException(
                'Set PLATFORM_SUPER_ADMIN_PASSWORD in .env before seeding the platform super admin.',
            );
        }

        $platformModules = array_fill_keys(\App\Services\Erp\ModuleRegistry::keys(), false);

        $platformOrg = Organization::create([
            'company_code' => $platformCode,
            'org_name' => 'Platform Administration',
            'org_email' => $platformEmail,
            'primary_tel' => '0700000000',
            'org_address' => 'Platform',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => $platformModules,
            'module_settings' => ['platform' => true],
        ]);

        $platformBranch = Branch::create([
            'organization_id' => $platformOrg->id,
            'branch_code' => 'HQ',
            'branch_name' => 'Platform HQ',
            'branch_type' => 'supermarket',
            'branch_phone' => '0700000000',
        ]);

        $platformRole = Role::create([
            'role_name' => 'Platform Operator',
            'scope' => 'org',
            'is_active' => true,
        ]);

        User::create([
            'organization_id' => $platformOrg->id,
            'branch_id' => $platformBranch->id,
            'role_id' => $platformRole->id,
            'username' => 'superadmin',
            'email' => $platformEmail,
            'password' => Hash::make($platformPassword),
            'full_name' => 'Platform Super Admin',
            'is_admin' => 0,
            'is_super_admin' => 1,
            'access_scope' => 'org',
            'login_channels' => ['backoffice'],
            'is_active' => true,
        ]);

        $this->command->info("Demo data seeded. Org manager: DEMO / admin / password");
        $this->command->info("Branch cashier (POS only, limited RBAC): DEMO / cashier / password");
        $this->command->info("Platform super admin: {$platformEmail} (org code optional) or {$platformCode} / superadmin");
    }

    protected function truncateDemoTables(): void
    {
        $skip = ['migrations'];

        if (! app()->environment('testing')) {
            $skip[] = 'payment_methods';
            $skip[] = 'lpo_statuses';
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $tables = collect(DB::select('SHOW TABLES'))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->reject(fn (string $table) => in_array($table, $skip, true));

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
