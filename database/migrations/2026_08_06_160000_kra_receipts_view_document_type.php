<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
    CASE
        WHEN LOWER(COALESCE(s.channel, '')) = 'pos' AND s.pos_order_num IS NOT NULL
        THEN s.pos_order_num
        ELSE COALESCE(kr.order_no, s.order_num)
    END AS order_no,
    s.order_num AS sale_order_num,
    s.pos_order_num,
    COALESCE(
        NULLIF(TRIM(c.customer_name), ''),
        NULLIF(TRIM(s.customer_name_override), ''),
        'Walk-in'
    ) AS customer_name,
    DATE(kr.created_at) AS receipt_date,
    kr.created_at AS receipt_at,
    kr.invoice_number,
    kr.serial_number,
    kr.signature_link,
    kr.receipt_signature,
    kr.kra_timestamp,
    kr.status,
    kr.error_message,
    kr.request_payload,
    kr.response_payload,
    CASE
        WHEN LOWER(COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.response_payload, '$.document_type')), ''),
            ''
        )) IN ('credit_note', 'credit', 'creditnote') THEN 'credit_note'
        WHEN LOWER(COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.InvoiceType')), ''),
            ''
        )) IN ('credit', 'credit_note', 'creditnote') THEN 'credit_note'
        ELSE 'sale'
    END AS document_type,
    COALESCE(
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.response_payload, '$.relevant_invoice_number')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.relevantInvoiceNumber')), '')
    ) AS relevant_invoice_number,
    s.branch_id,
    b.branch_name,
    s.channel,
    s.order_total,
    s.amount_paid,
    s.total_vat,
    COALESCE(kr.organization_id, s.organization_id) AS organization_id
FROM kra_responses kr
INNER JOIN sales s ON s.id = kr.sale_id
LEFT JOIN branches b ON b.id = s.branch_id
LEFT JOIN customers c ON c.customer_num = s.customer_num
    AND c.organization_id = s.organization_id
    AND c.deleted_at IS NULL
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_kra_receipts');
        DB::statement(<<<'SQL'
CREATE VIEW v_kra_receipts AS
SELECT
    kr.id AS kra_response_id,
    kr.sale_id,
    CASE
        WHEN LOWER(COALESCE(s.channel, '')) = 'pos' AND s.pos_order_num IS NOT NULL
        THEN s.pos_order_num
        ELSE COALESCE(kr.order_no, s.order_num)
    END AS order_no,
    s.order_num AS sale_order_num,
    s.pos_order_num,
    COALESCE(
        NULLIF(TRIM(c.customer_name), ''),
        NULLIF(TRIM(s.customer_name_override), ''),
        'Walk-in'
    ) AS customer_name,
    DATE(kr.created_at) AS receipt_date,
    kr.created_at AS receipt_at,
    kr.invoice_number,
    kr.serial_number,
    kr.signature_link,
    kr.receipt_signature,
    kr.kra_timestamp,
    kr.status,
    kr.error_message,
    kr.request_payload,
    kr.response_payload,
    s.branch_id,
    b.branch_name,
    s.channel,
    s.order_total,
    s.amount_paid,
    s.total_vat,
    COALESCE(kr.organization_id, s.organization_id) AS organization_id
FROM kra_responses kr
INNER JOIN sales s ON s.id = kr.sale_id
LEFT JOIN branches b ON b.id = s.branch_id
LEFT JOIN customers c ON c.customer_num = s.customer_num
    AND c.organization_id = s.organization_id
    AND c.deleted_at IS NULL
SQL);
    }
};
