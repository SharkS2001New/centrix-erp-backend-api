<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductionRoleSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    protected array $rolePermissions = [
        'Branch Manager' => [
            'dashboard.overview.view',
            'sales.dashboard.view',
            'sales.orders.view',
            'sales.orders.create',
            'sales.orders.edit',
            'sales.returns.view',
            'sales.returns.create',
            'sales.vouchers.view',
            'sales.reservations.view',
            'payments.sale_payments.view',
            'payments.sale_payments.create',
            'pos.end_of_day.view',
            'inventory.stock.view',
            'inventory.receipts.view',
            'inventory.movements.view',
            'inventory.transfers.view',
            'inventory.transfers.create',
            'inventory.damages.view',
            'inventory.damages.create',
            'inventory.stock_take.view',
            'purchasing.lpo.view',
            'purchasing.lpo.create',
            'purchasing.lpo.edit',
            'purchasing.suppliers.view',
            'purchasing.supplier_payments.view',
            'purchasing.supplier_payments.create',
            'purchasing.supplier_returns.view',
            'purchasing.supplier_returns.create',
            'customers.customers.view',
            'customers.customers.edit',
            'reports.hub.view',
            'reports.daily_sales.view',
            'reports.stock_on_hand.view',
            'reports.profit_loss.view',
            'admin.branches.view',
            'admin.users.view',
        ],
        'Stock Clerk' => [
            'dashboard.overview.view',
            'inventory.stock.view',
            'inventory.receipts.view',
            'inventory.receipts.create',
            'inventory.movements.view',
            'inventory.transfers.view',
            'inventory.transfers.create',
            'inventory.damages.view',
            'inventory.damages.create',
            'inventory.stock_take.view',
            'inventory.stock_take.create',
            'purchasing.lpo.view',
            'purchasing.suppliers.view',
            'purchasing.supplier_returns.view',
            'purchasing.supplier_returns.create',
        ],
        'Accountant' => [
            'dashboard.overview.view',
            'accounting.dashboard.view',
            'accounting.chart_of_accounts.view',
            'accounting.journal_entries.view',
            'accounting.journal_entries.create',
            'accounting.fiscal_periods.view',
            'accounting.general_ledger.view',
            'accounting.trial_balance.view',
            'accounting.profit_loss.view',
            'accounting.balance_sheet.view',
            'accounting.cash_flow.view',
            'accounting.accounts_receivable.view',
            'accounting.accounts_payable.view',
            'accounting.expenses.view',
            'accounting.export_queue.view',
            'accounting.account_mappings.view',
            'purchasing.supplier_payments.view',
            'admin.payment_methods.view',
            'reports.hub.view',
            'reports.profit_loss.view',
            'reports.expenses.view',
            'reports.journal_register.view',
        ],
        'Payroll Clerk' => [
            'dashboard.overview.view',
            'hr.employees.view',
            'hr.departments.view',
            'hr.kpis.view',
            'hr.attendance.view',
            'hr.leave.view',
            'hr.payroll.view',
            'hr.payroll.create',
            'reports.hub.view',
        ],
        'Viewer' => [
            'dashboard.overview.view',
            'catalogue.products.view',
            'catalogue.categories.view',
            'sales.dashboard.view',
            'sales.orders.view',
            'inventory.stock.view',
            'purchasing.lpo.view',
            'purchasing.suppliers.view',
            'customers.customers.view',
            'reports.hub.view',
            'reports.daily_sales.view',
            'reports.stock_on_hand.view',
        ],
    ];

    public function run(): void
    {
        foreach ($this->rolePermissions as $roleName => $codes) {
            $role = Role::query()->firstOrCreate(
                ['role_name' => $roleName],
                ['scope' => str_contains(strtolower($roleName), 'manager') ? 'org' : 'branch', 'is_active' => true],
            );

            foreach ($codes as $code) {
                $permissionId = Permission::where('permission_code', $code)->value('id');
                if (! $permissionId) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }
}
