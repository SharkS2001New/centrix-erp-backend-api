<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_accounts_receivable_summary');
        DB::statement("
            CREATE VIEW v_accounts_receivable_summary AS
            SELECT
                c.customer_num,
                c.customer_name,
                c.phone_number,
                r.route_name,
                COALESCE(c.current_balance, 0) AS customer_balance,
                COALESCE(inv.open_invoice_total, 0) AS invoice_balance_due,
                COALESCE(inv.open_invoice_count, 0) AS open_invoices,
                COALESCE(credit.credit_sales_outstanding, 0) AS credit_sales_outstanding,
                COALESCE(c.current_balance, 0)
                    + COALESCE(inv.open_invoice_total, 0)
                    + COALESCE(credit.credit_sales_outstanding, 0) AS total_outstanding
            FROM customers c
            LEFT JOIN routes r ON c.route_id = r.id
            LEFT JOIN (
                SELECT
                    ci.customer_num,
                    SUM(ci.balance_due) AS open_invoice_total,
                    COUNT(*) AS open_invoice_count
                FROM customer_invoices ci
                WHERE ci.payment_status IN (0, 1) AND ci.deleted_at IS NULL
                GROUP BY ci.customer_num
            ) inv ON inv.customer_num = c.customer_num
            LEFT JOIN (
                SELECT
                    s.customer_num,
                    SUM(s.order_total - s.amount_paid) AS credit_sales_outstanding
                FROM sales s
                WHERE s.status = 'completed'
                    AND s.is_credit_sale = 1
                    AND s.payment_status IN ('unpaid', 'partial')
                    AND s.customer_num IS NOT NULL
                GROUP BY s.customer_num
            ) credit ON credit.customer_num = c.customer_num
            WHERE c.deleted_at IS NULL
                AND (
                    COALESCE(c.current_balance, 0) > 0
                    OR COALESCE(inv.open_invoice_total, 0) > 0
                    OR COALESCE(credit.credit_sales_outstanding, 0) > 0
                )
        ");

        DB::statement('DROP VIEW IF EXISTS v_supplier_payables');
        DB::statement("
            CREATE VIEW v_supplier_payables AS
            SELECT
                s.id AS supplier_id,
                s.supplier_code,
                s.supplier_name,
                COALESCE(rec.received_value, 0) AS received_value,
                COALESCE(ret.return_value, 0) AS return_value,
                GREATEST(
                    COALESCE(rec.received_value, 0) - COALESCE(ret.return_value, 0),
                    0
                ) AS balance_due,
                COALESCE(rec.open_lpo_count, 0) AS open_lpo_count
            FROM suppliers s
            LEFT JOIN (
                SELECT
                    l.supplier_id,
                    SUM(COALESCE(t.received_qty, 0) * COALESCE(t.cost_price, 0)) AS received_value,
                    COUNT(DISTINCT l.lpo_no) AS open_lpo_count
                FROM lpo_txn t
                JOIN lpo_mst l ON l.lpo_no = t.lpo_no
                WHERE COALESCE(t.received_qty, 0) > 0
                GROUP BY l.supplier_id
            ) rec ON rec.supplier_id = s.id
            LEFT JOIN (
                SELECT sr.supplier_id, SUM(sr.quantity * COALESCE(p.unit_price, 0)) AS return_value
                FROM supplier_returns sr
                JOIN products p ON p.product_code = sr.product_code
                GROUP BY sr.supplier_id
            ) ret ON ret.supplier_id = s.id
            WHERE GREATEST(
                COALESCE(rec.received_value, 0) - COALESCE(ret.return_value, 0),
                0
            ) > 0
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_supplier_payables');
        DB::statement('DROP VIEW IF EXISTS v_accounts_receivable_summary');
    }
};
