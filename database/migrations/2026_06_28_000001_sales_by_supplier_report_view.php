<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected string $legacySalesFilter = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.fulfillment_meta, '$.legacy_import')), 'false') <> 'true'";

    public function up(): void
    {
        $legacy = $this->legacySalesFilter;

        DB::statement('DROP VIEW IF EXISTS v_sales_by_supplier');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_supplier AS
SELECT
    s.organization_id,
    sup.id AS supplier_id,
    COALESCE(sup.supplier_name, 'No supplier') AS supplier_name,
    COALESCE(sup.supplier_code, '') AS supplier_code,
    DATE(s.completed_at) AS sale_date,
    s.branch_id,
    s.channel,
    COUNT(DISTINCT si.product_code) AS products_sold,
    COUNT(DISTINCT s.id) AS order_count,
    SUM(si.quantity) AS qty_sold,
    SUM(si.amount) AS total_revenue,
    SUM(si.product_vat) AS total_vat,
    SUM(si.discount_given) AS total_discount
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN products p ON si.product_code = p.product_code AND p.organization_id = s.organization_id
LEFT JOIN suppliers sup ON p.supplier_id = sup.id AND sup.organization_id = s.organization_id
WHERE s.status = 'completed'
  AND s.archived = 0
  AND {$legacy}
GROUP BY s.organization_id, sup.id, sup.supplier_name, sup.supplier_code, DATE(s.completed_at), s.branch_id, s.channel
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_sales_by_supplier');
    }
};
