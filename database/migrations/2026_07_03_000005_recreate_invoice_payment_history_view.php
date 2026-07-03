<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_invoice_payment_history');
        DB::statement(<<<'SQL'
CREATE VIEW v_invoice_payment_history AS
SELECT
    cip.id AS payment_id,
    cip.customer_invoice_id,
    cip.organization_id,
    c.branch_id,
    cip.customer_num,
    c.customer_name,
    ci.invoice_number,
    cip.date_paid,
    cip.amount_paid,
    pm.method_name,
    u.username AS received_by,
    cip.reference_number
FROM customer_invoice_payments cip
JOIN customers c ON cip.customer_num = c.customer_num
    AND cip.organization_id = c.organization_id
JOIN customer_invoices ci ON cip.customer_invoice_id = ci.id
    AND ci.organization_id = cip.organization_id
JOIN payment_methods pm ON cip.payment_method_id = pm.id
JOIN users u ON cip.received_by = u.id
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_invoice_payment_history');
        DB::statement(<<<'SQL'
CREATE VIEW v_invoice_payment_history AS
SELECT
    cip.id AS payment_id,
    cip.customer_num,
    c.customer_name,
    ci.invoice_number,
    cip.date_paid,
    cip.amount_paid,
    pm.method_name,
    u.username AS received_by,
    cip.reference_number
FROM customer_invoice_payments cip
JOIN customers c ON cip.customer_num = c.customer_num
JOIN customer_invoices ci ON cip.customer_invoice_id = ci.id
JOIN payment_methods pm ON cip.payment_method_id = pm.id
JOIN users u ON cip.received_by = u.id
SQL);
    }
};
