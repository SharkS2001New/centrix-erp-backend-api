<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compliance fiscalized_total / fiscalized_vat must match eTIMS portal uploads:
 * successful live fiscalizations only (exclude test-mode SignType=0 / is_test).
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
    SUM(
        CASE
            WHEN kr.status <> 'success' THEN 0
            WHEN LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.is_test')), '')) IN ('true', '1')
              OR JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.SignType')) = '0'
            THEN 0
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
            WHEN LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.is_test')), '')) IN ('true', '1')
              OR JSON_UNQUOTE(JSON_EXTRACT(kr.request_payload, '$.sign_structure.SignType')) = '0'
            THEN 0
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
        // Prior migration 2026_11_08_204500 recreates the previous definition if rolled back in order.
    }
};
