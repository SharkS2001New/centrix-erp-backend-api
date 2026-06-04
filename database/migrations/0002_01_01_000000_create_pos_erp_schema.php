<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $sql = file_get_contents(database_path('sql/schema.sql'));
        $clean = preg_replace('/^--.*$/m', '', $sql);
        $clean = $this->normalizeDelimiters($clean);
        DB::unprepared('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->splitStatements($clean) as $statement) {
            $statement = trim($statement);
            if ($statement === '') continue;
            DB::unprepared($statement);
        }
        DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        DB::unprepared('SET FOREIGN_KEY_CHECKS=0');
        $objects = [
            'v_invoice_payment_history','v_stock_transfers','v_discount_summary',
            'v_top_debtors','v_journal_register','v_payroll_summary','v_till_session_summary',
            'v_category_sales','v_vat_collected','v_sales_pipeline','v_stock_reservations_active',
            'v_kra_receipts','v_credit_outstanding','v_stock_receipts_detail',
            'v_supplier_returns_detail','v_damages_summary','v_expenses_summary',
            'v_open_lpo_lines','v_low_stock','v_payment_collection','v_sales_by_channel',
            'v_daily_sales','v_stock_valuation','v_sales_by_user',
            'v_stock_chain','v_route_loading_summary','v_profit_loss_summary',
            'v_purchases_by_supplier','v_stock_on_hand','v_ar_aging',
            'v_sales_by_customer','v_sales_by_product','v_eod_cashier_summary',
            'payroll_lines','payroll_runs','pay_periods','employee_documents','employee_attendance',
            'employee_cash_advances','employee_overtime','employee_deductions','payroll_deduction_types',
            'employee_next_of_kin','employee_emergency_contacts','employee_bank_accounts',
            'employees','positions','departments',
            'journal_entry_lines','journal_entries','chart_of_accounts',
            'stock_take_lines','stock_take_sessions','drivers','vehicles',
            'system_settings','audit_logs','kra_responses','expenses',
            'expense_groups','returns','lpo_supplier_invoices','lpo_attachments',
            'lpo_txn','lpo_mst','lpo_statuses','customer_invoice_payments',
            'customer_invoices','stock_reservations','cart_lines','temporary_carts',
            'sale_payments','sale_items','sales','payment_methods',
            'supplier_returns','stock_receipts','damages','stock_movement_history',
            'inventory_transactions','current_stock','customers','routes',
            'price_history','retail_package_settings','products',
            'sub_categories','categories','uoms','vats','suppliers',
            'till_float_sessions','tills','users','role_permissions',
            'permissions','roles','branches','organizations',
        ];
        foreach ($objects as $name) {
            DB::unprepared("DROP VIEW IF EXISTS `{$name}`");
            DB::unprepared("DROP TABLE IF EXISTS `{$name}`");
        }
        DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
    }

    private function normalizeDelimiters(string $sql): string
    {
        return preg_replace_callback(
            '/DELIMITER\s+\$\$(.*?)DELIMITER\s*;/s',
            function ($m) {
                $parts = array_filter(array_map('trim', explode('$$', $m[1])));
                $out = [];
                foreach ($parts as $p) {
                    if ($p === '') continue;
                    $out[] = str_replace(';', "\x01", $p) . ';';
                }
                return implode("\n", $out);
            },
            $sql
        );
    }

    private function splitStatements(string $sql): array
    {
        $parts = preg_split('/;\s*(?=\n|$)/', $sql);
        return array_map(fn ($s) => str_replace("\x01", ';', $s), $parts);
    }
};
