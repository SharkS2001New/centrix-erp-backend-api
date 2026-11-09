<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KRA invoices / compliance tallies must match what was transmitted to eTIMS
 * (Comstore PLU + sign_structure), not Centrix sale headers that can diverge
 * after order_discount / credit notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_kra_receipts_replay');
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
    s.cashier_id,
    COALESCE(
        NULLIF(TRIM(u.full_name), ''),
        NULLIF(TRIM(u.username), '')
    ) AS cashier_name,
    s.branch_id,
    b.branch_name,
    s.channel,
    /* Centrix payable header (reference). */
    s.order_total AS sale_order_total,
    s.total_vat AS sale_total_vat,
    s.amount_paid,
    /*
     * Transacted / eTIMS-facing totals from the stored Comstore payload.
     * Credit notes are signed negative so period SUM matches portal net.
     * Fallback to Centrix headers when no payload (skipped / pre-payload rows).
     */
    CASE
        WHEN JSON_EXTRACT(kr.request_payload, '$.sign_structure') IS NULL THEN s.order_total
        ELSE
            CASE
                WHEN LOWER(COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.response_payload, '$.document_type')), ''),
                    ''
                )) IN ('credit_note', 'credit', 'creditnote')
                  OR LOWER(COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.InvoiceType')), ''),
                    ''
                )) IN ('credit', 'credit_note', 'creditnote')
                THEN -1 ELSE 1
            END * ROUND(
                COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.CashAmt')) AS DECIMAL(18,4)), 0)
              + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.CheckAmt')) AS DECIMAL(18,4)), 0)
              + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.CardAmt')) AS DECIMAL(18,4)), 0)
            , 2)
    END AS order_total,
    CASE
        WHEN JSON_EXTRACT(kr.request_payload, '$.sign_structure') IS NULL THEN s.total_vat
        ELSE
            CASE
                WHEN LOWER(COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.response_payload, '$.document_type')), ''),
                    ''
                )) IN ('credit_note', 'credit', 'creditnote')
                  OR LOWER(COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.InvoiceType')), ''),
                    ''
                )) IN ('credit', 'credit_note', 'creditnote')
                THEN -1 ELSE 1
            END * ROUND(
                COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure."Vat B(16.00%) value"')) AS DECIMAL(18,4)), 0)
              + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure."Vat E(8%) value"')) AS DECIMAL(18,4)), 0)
            , 2)
    END AS total_vat,
    COALESCE(kr.organization_id, s.organization_id) AS organization_id
FROM kra_responses kr
INNER JOIN sales s ON s.id = kr.sale_id
LEFT JOIN users u ON u.id = s.cashier_id
LEFT JOIN branches b ON b.id = s.branch_id
LEFT JOIN customers c ON c.customer_num = s.customer_num
    AND c.organization_id = s.organization_id
    AND c.deleted_at IS NULL
SQL);

        DB::statement(<<<'SQL'
CREATE VIEW v_kra_receipts_replay AS
SELECT
    receipts.*,
    DATE(COALESCE(sale.completed_at, sale.created_at)) AS sale_date,
    CASE
        WHEN LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(receipts.request_payload, '$.is_test')), '')) IN ('true', '1')
          OR JSON_UNQUOTE(JSON_EXTRACT(receipts.request_payload, '$.sign_structure.SignType')) = '0'
        THEN 1
        ELSE 0
    END AS is_test,
    CASE
        WHEN JSON_UNQUOTE(JSON_EXTRACT(receipts.response_payload, '$.live_replay_status')) = 'processing'
        THEN 1
        ELSE 0
    END AS is_live_replay_in_progress
FROM v_kra_receipts receipts
INNER JOIN sales sale ON sale.id = receipts.sale_id
SQL);

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
    SUM(
        CASE
            WHEN kr.status <> 'success' THEN 0
            WHEN JSON_EXTRACT(kr.request_payload, '$.sign_structure') IS NULL THEN s.order_total
            ELSE
                CASE
                    WHEN LOWER(COALESCE(
                        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.response_payload, '$.document_type')), ''),
                        ''
                    )) IN ('credit_note', 'credit', 'creditnote')
                      OR LOWER(COALESCE(
                        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.InvoiceType')), ''),
                        ''
                    )) IN ('credit', 'credit_note', 'creditnote')
                    THEN -1 ELSE 1
                END * ROUND(
                    COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.CashAmt')) AS DECIMAL(18,4)), 0)
                  + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.CheckAmt')) AS DECIMAL(18,4)), 0)
                  + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.CardAmt')) AS DECIMAL(18,4)), 0)
                , 2)
        END
    ) AS fiscalized_total,
    SUM(CASE WHEN kr.status = 'failed' THEN s.order_total ELSE 0 END) AS failed_total,
    SUM(s.order_total) AS order_total,
    SUM(
        CASE
            WHEN kr.status <> 'success' THEN 0
            WHEN JSON_EXTRACT(kr.request_payload, '$.sign_structure') IS NULL THEN COALESCE(s.total_vat, 0)
            ELSE
                CASE
                    WHEN LOWER(COALESCE(
                        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.response_payload, '$.document_type')), ''),
                        ''
                    )) IN ('credit_note', 'credit', 'creditnote')
                      OR LOWER(COALESCE(
                        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.InvoiceType')), ''),
                        ''
                    )) IN ('credit', 'credit_note', 'creditnote')
                    THEN -1 ELSE 1
                END * ROUND(
                    COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure."Vat B(16.00%) value"')) AS DECIMAL(18,4)), 0)
                  + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure."Vat E(8%) value"')) AS DECIMAL(18,4)), 0)
                , 2)
        END
    ) AS fiscalized_vat,
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
    }

    public function down(): void
    {
        // Replay + receipts restored by re-running prior migrations if needed.
        DB::statement('DROP VIEW IF EXISTS v_kra_receipts_replay');
        DB::statement('DROP VIEW IF EXISTS v_kra_compliance_summary');
        DB::statement('DROP VIEW IF EXISTS v_kra_receipts');
    }
};
