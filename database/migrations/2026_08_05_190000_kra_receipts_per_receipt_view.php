<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compliance → KRA receipts: one row per fiscal receipt (order #, CU #, date, …)
 * instead of aggregated daily counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_kra_receipts');
        DB::statement(<<<'SQL'
CREATE VIEW v_kra_receipts AS
SELECT
    kr.id AS kra_response_id,
    kr.sale_id,
    COALESCE(kr.order_no, s.order_num) AS order_no,
    s.order_num AS sale_order_num,
    DATE(kr.created_at) AS receipt_date,
    kr.created_at AS receipt_at,
    kr.invoice_number,
    kr.serial_number,
    kr.signature_link,
    kr.receipt_signature,
    kr.kra_timestamp,
    kr.status,
    kr.error_message,
    s.branch_id,
    b.branch_name,
    s.channel,
    s.order_total,
    COALESCE(kr.organization_id, s.organization_id) AS organization_id
FROM kra_responses kr
INNER JOIN sales s ON s.id = kr.sale_id
LEFT JOIN branches b ON b.id = s.branch_id
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_kra_receipts');
        DB::statement(<<<'SQL'
CREATE VIEW v_kra_receipts AS
SELECT
    DATE(kr.created_at) AS receipt_date,
    s.branch_id,
    s.channel,
    kr.status,
    COUNT(*) AS receipt_count,
    SUM(s.order_total) AS order_total
FROM kra_responses kr
JOIN sales s ON kr.sale_id = s.id
GROUP BY DATE(kr.created_at), s.branch_id, s.channel, kr.status
SQL);
    }
};
