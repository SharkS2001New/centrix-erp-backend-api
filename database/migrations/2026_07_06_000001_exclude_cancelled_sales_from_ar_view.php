<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_accounts_receivable_summary');
        DB::statement(<<<SQL
CREATE VIEW v_accounts_receivable_summary AS
SELECT
    c.organization_id,
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
        ci.organization_id,
        ci.customer_num,
        SUM(ci.balance_due) AS open_invoice_total,
        COUNT(*) AS open_invoice_count
    FROM customer_invoices ci
    LEFT JOIN sales s ON s.id = ci.sale_id
    WHERE ci.payment_status IN (0, 1)
        AND ci.deleted_at IS NULL
        AND (s.id IS NULL OR s.status NOT IN ('cancelled', 'expired'))
    GROUP BY ci.organization_id, ci.customer_num
) inv ON inv.customer_num = c.customer_num AND inv.organization_id = c.organization_id
LEFT JOIN (
    SELECT
        s.organization_id,
        s.customer_num,
        SUM(s.order_total - s.amount_paid) AS credit_sales_outstanding
    FROM sales s
    WHERE s.status = 'completed'
        AND s.is_credit_sale = 1
        AND s.payment_status IN ('unpaid', 'partial')
        AND s.customer_num IS NOT NULL
    GROUP BY s.organization_id, s.customer_num
) credit ON credit.customer_num = c.customer_num AND credit.organization_id = c.organization_id
WHERE c.deleted_at IS NULL
    AND (
        COALESCE(c.current_balance, 0) > 0
        OR COALESCE(inv.open_invoice_total, 0) > 0
        OR COALESCE(credit.credit_sales_outstanding, 0) > 0
    )
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_accounts_receivable_summary');
        DB::statement(<<<SQL
CREATE VIEW v_accounts_receivable_summary AS
SELECT
    c.organization_id,
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
        ci.organization_id,
        ci.customer_num,
        SUM(ci.balance_due) AS open_invoice_total,
        COUNT(*) AS open_invoice_count
    FROM customer_invoices ci
    WHERE ci.payment_status IN (0, 1) AND ci.deleted_at IS NULL
    GROUP BY ci.organization_id, ci.customer_num
) inv ON inv.customer_num = c.customer_num AND inv.organization_id = c.organization_id
LEFT JOIN (
    SELECT
        s.organization_id,
        s.customer_num,
        SUM(s.order_total - s.amount_paid) AS credit_sales_outstanding
    FROM sales s
    WHERE s.status = 'completed'
        AND s.is_credit_sale = 1
        AND s.payment_status IN ('unpaid', 'partial')
        AND s.customer_num IS NOT NULL
    GROUP BY s.organization_id, s.customer_num
) credit ON credit.customer_num = c.customer_num AND credit.organization_id = c.organization_id
WHERE c.deleted_at IS NULL
    AND (
        COALESCE(c.current_balance, 0) > 0
        OR COALESCE(inv.open_invoice_total, 0) > 0
        OR COALESCE(credit.credit_sales_outstanding, 0) > 0
    )
SQL);
    }
};
