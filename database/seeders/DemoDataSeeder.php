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

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'stock_reservations', 'cart_lines', 'temporary_carts', 'customer_invoice_payments', 'customer_invoices',
            'sale_payments', 'sale_items', 'sales', 'inventory_transactions', 'current_stock',
            'damages', 'stock_receipts', 'supplier_returns', 'returns', 'lpo_supplier_invoices',
            'lpo_attachments', 'lpo_txn', 'lpo_mst', 'expenses', 'kra_responses', 'audit_logs',
            'retail_package_settings', 'price_history', 'products', 'sub_categories', 'categories',
            'uoms', 'customers', 'routes', 'suppliers', 'till_float_sessions', 'tills', 'users',
            'role_permissions', 'permissions', 'roles', 'branches', 'organizations', 'system_settings',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

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
                'sales' => ['auto_assign_truck' => true, 'auto_assign_driver' => true],
                'inventory' => ['reserve_stock_on_cart' => true, 'default_pos_sale_location' => 'shop'],
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

        $rAdmin = Role::create(['role_name' => 'Administrator', 'scope' => 'org']);
        $rCash = Role::create(['role_name' => 'Cashier', 'scope' => 'branch']);

        $permDefs = [
            ['Process Sales', 'sales.create', 'sales'],
            ['Manage Orders', 'sales.manage', 'sales'],
            ['Manage Payments', 'payments.manage', 'payments'],
            ['View Stock', 'inventory.view', 'inventory'],
            ['Manage Stock', 'inventory.manage', 'inventory'],
            ['View Reports', 'reports.view', 'reports'],
            ['Manage Purchasing', 'purchasing.manage', 'purchasing'],
            ['Manage Accounting', 'accounting.manage', 'accounting'],
            ['Manage HR Payroll', 'hr.manage', 'hr'],
            ['Administration', 'admin.manage', 'admin'],
            ['POS Till', 'pos.till', 'pos'],
            ['Manage Products', 'products.manage', 'catalogue'],
        ];
        foreach ($permDefs as [$n, $c, $m]) {
            $perm = Permission::firstOrCreate(
                ['permission_code' => $c],
                ['permission_name' => $n, 'module' => $m]
            );
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $rAdmin->id,
                'permission_id' => $perm->id,
            ]);
        }
        $cashierPerms = ['sales.create', 'payments.manage', 'inventory.view', 'pos.till'];
        foreach ($cashierPerms as $code) {
            $pid = Permission::where('permission_code', $code)->value('id');
            if ($pid) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $rCash->id,
                    'permission_id' => $pid,
                ]);
            }
        }

        $admin = User::create([
            'organization_id' => $org->id,
            'branch_id' => $hq->id,
            'role_id' => $rAdmin->id,
            'username' => 'admin',
            'email' => 'admin@demo.co.ke',
            'password' => Hash::make('password'),
            'full_name' => 'System Administrator',
            'is_admin' => 1,
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

        User::create([
            'organization_id' => $org->id,
            'branch_id' => $hq->id,
            'role_id' => $rCash->id,
            'username' => 'cashier',
            'password' => Hash::make('password'),
            'full_name' => 'Peter Otieno',
            'is_active' => true,
        ]);

        $vatStd = Vat::where('vat_code', 'V')->first()
            ?? Vat::create(['vat_code' => 'V', 'vat_name' => 'Standard Rated', 'vat_percentage' => 16, 'created_by' => $admin->id]);

        $uomKg = Uom::create([
            'conversion_factor' => 1, 'full_name' => 'Kilogram', 'uom_type' => 'kg',
            'is_base_unit' => true, 'created_by' => $admin->id,
        ]);

        $sup = Supplier::create([
            'supplier_code' => 'SUP-001',
            'supplier_name' => 'Mumias Sugar Co.',
            'phone' => '0711222333',
            'organization_id' => $org->id,
            'contacts' => [['label' => 'Accounts', 'phone' => '0711999000', 'email' => 'accounts@mumias.co.ke']],
            'created_by' => $admin->id,
        ]);

        $cat = Category::create(['category_name' => 'Food & Beverage', 'created_by' => $admin->id]);
        $sub = SubCategory::create(['category_id' => $cat->id, 'subcategory_name' => 'Sugar', 'created_by' => $admin->id]);

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
            'created_by' => $admin->id,
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

        $route = RouteModel::create(['route_name' => 'Nairobi East', 'route_markup_price' => 10, 'direction' => 'East']);

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
            'working_amount' => 5000,
            'float_breakdown' => ['CASH' => 5000],
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
            'order_num' => 90001,
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
            'order_num' => 90002,
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
            'order_num' => 90003,
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

        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '1000'],
            ['account_name' => 'Cash', 'account_type' => 'asset', 'is_active' => true]
        );
        \App\Models\ChartOfAccount::firstOrCreate(
            ['organization_id' => $org->id, 'account_code' => '4000'],
            ['account_name' => 'Sales Revenue', 'account_type' => 'revenue', 'is_active' => true]
        );

        $this->command->info('Demo data seeded (schema v3). Login: admin / password');
    }
}
