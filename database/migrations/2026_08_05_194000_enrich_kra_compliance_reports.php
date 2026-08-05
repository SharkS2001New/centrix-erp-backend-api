<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enrich overall KRA compliance summary (VAT, distinct sales, CU counts)
 * and add VAT on per-receipt KRA rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_kra_compliance_summary');
        DB::statement(<<<'SQL'
CREATE VIEW v_kra_compliance_summary AS
SELECT
    DATE(kr.created_at) AS receipt_date,
    s.branch_id,
    b.branch_name,
    s.channel,
    COUNT(*) AS receipt_count,
    COUNT(DISTINCT s.id) AS sale_count,
    SUM(CASE WHEN kr.status = 'success' THEN 1 ELSE 0 END) AS success_count,
    SUM(CASE WHEN kr.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
    SUM(CASE WHEN kr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
    COUNT(DISTINCT CASE
        WHEN kr.status = 'success' AND kr.invoice_number IS NOT NULL AND kr.invoice_number != ''
        THEN kr.invoice_number
    END) AS cu_invoice_count,
    ROUND(
        100 * SUM(CASE WHEN kr.status = 'success' THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0),
        1
    ) AS success_rate_pct,
    SUM(CASE WHEN kr.status = 'success' THEN s.order_total ELSE 0 END) AS fiscalized_total,
    SUM(CASE WHEN kr.status = 'failed' THEN s.order_total ELSE 0 END) AS failed_total,
    SUM(s.order_total) AS order_total,
    SUM(CASE WHEN kr.status = 'success' THEN COALESCE(s.total_vat, 0) ELSE 0 END) AS fiscalized_vat,
    SUM(CASE WHEN kr.status = 'failed' THEN COALESCE(s.total_vat, 0) ELSE 0 END) AS failed_vat,
    SUM(COALESCE(s.total_vat, 0)) AS total_vat,
    COALESCE(kr.organization_id, s.organization_id) AS organization_id
FROM kra_responses kr
INNER JOIN sales s ON s.id = kr.sale_id
LEFT JOIN branches b ON b.id = s.branch_id
GROUP BY
    DATE(kr.created_at),
    s.branch_id,
    b.branch_name,
    s.channel,
    COALESCE(kr.organization_id, s.organization_id)
SQL);

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
    s.total_vat,
    COALESCE(kr.organization_id, s.organization_id) AS organization_id
FROM kra_responses kr
INNER JOIN sales s ON s.id = kr.sale_id
LEFT JOIN branches b ON b.id = s.branch_id
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_kra_compliance_summary');
        DB::statement(<<<'SQL'
CREATE VIEW v_kra_compliance_summary AS
SELECT
    DATE(kr.created_at) AS receipt_date,
    s.branch_id,
    b.branch_name,
    s.channel,
    COUNT(*) AS receipt_count,
    SUM(CASE WHEN kr.status = 'success' THEN 1 ELSE 0 END) AS success_count,
    SUM(CASE WHEN kr.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
    SUM(CASE WHEN kr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
    ROUND(
        100 * SUM(CASE WHEN kr.status = 'success' THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0),
        1
    ) AS success_rate_pct,
    SUM(CASE WHEN kr.status = 'success' THEN s.order_total ELSE 0 END) AS fiscalized_total,
    SUM(CASE WHEN kr.status = 'failed' THEN s.order_total ELSE 0 END) AS failed_total,
    SUM(s.order_total) AS order_total,
    COALESCE(kr.organization_id, s.organization_id) AS organization_id
FROM kra_responses kr
INNER JOIN sales s ON s.id = kr.sale_id
LEFT JOIN branches b ON b.id = s.branch_id
GROUP BY
    DATE(kr.created_at),
    s.branch_id,
    b.branch_name,
    s.channel,
    COALESCE(kr.organization_id, s.organization_id)
SQL);

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
};
