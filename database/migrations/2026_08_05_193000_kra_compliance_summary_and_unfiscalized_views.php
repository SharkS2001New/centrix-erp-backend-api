<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compliance reports:
 * - Overall KRA summary by day/branch/channel
 * - Completed sales missing a successful fiscal receipt
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

        DB::statement('DROP VIEW IF EXISTS v_kra_unfiscalized_sales');
        DB::statement(<<<'SQL'
CREATE VIEW v_kra_unfiscalized_sales AS
SELECT
    DATE(COALESCE(s.completed_at, s.created_at)) AS sale_date,
    COALESCE(s.completed_at, s.created_at) AS sale_at,
    s.id AS sale_id,
    s.order_num AS order_no,
    s.branch_id,
    b.branch_name,
    s.channel,
    s.status AS sale_status,
    s.payment_status,
    s.order_total,
    s.total_vat,
    s.organization_id,
    (
        SELECT kr.status
        FROM kra_responses kr
        WHERE kr.sale_id = s.id
        ORDER BY kr.id DESC
        LIMIT 1
    ) AS last_kra_status,
    (
        SELECT kr.error_message
        FROM kra_responses kr
        WHERE kr.sale_id = s.id
        ORDER BY kr.id DESC
        LIMIT 1
    ) AS last_kra_error
FROM sales s
LEFT JOIN branches b ON b.id = s.branch_id
WHERE s.archived = 0
  AND s.deleted_at IS NULL
  AND s.status IN ('completed', 'paid', 'delivered')
  AND NOT EXISTS (
      SELECT 1
      FROM kra_responses kr
      WHERE kr.sale_id = s.id
        AND kr.status = 'success'
  )
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_kra_compliance_summary');
        DB::statement('DROP VIEW IF EXISTS v_kra_unfiscalized_sales');
    }
};
