<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expose last kra_responses.id on unfiscalized sales so the UI can load payloads
 * for "View full reason" (item highlight + fix suggestion).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_kra_unfiscalized_sales');
        DB::statement(<<<'SQL'
CREATE VIEW v_kra_unfiscalized_sales AS
SELECT
    DATE(COALESCE(s.completed_at, s.created_at)) AS sale_date,
    COALESCE(s.completed_at, s.created_at) AS sale_at,
    s.id AS sale_id,
    s.order_num AS order_no,
    s.pos_order_num,
    s.branch_id,
    b.branch_name,
    s.channel,
    s.status AS sale_status,
    s.payment_status,
    s.order_total,
    s.total_vat,
    s.organization_id,
    (
        SELECT kr.id
        FROM kra_responses kr
        WHERE kr.sale_id = s.id
        ORDER BY kr.id DESC
        LIMIT 1
    ) AS last_kra_response_id,
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
};
