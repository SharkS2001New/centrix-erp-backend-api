<?php

use App\Services\Sales\CentrixSalesScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected string $legacySalesFilter;

    public function __construct()
    {
        $this->legacySalesFilter = CentrixSalesScope::legacyExcludeSql('s');
    }

    public function up(): void
    {
        $legacy = $this->legacySalesFilter;

        DB::statement('DROP VIEW IF EXISTS v_sales_by_product');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_product AS
SELECT
    s.organization_id,
    si.product_code,
    p.product_name,
    DATE(s.completed_at) AS sale_date,
    s.branch_id,
    s.channel,
    SUM(si.quantity) AS qty_sold,
    si.uom AS sell_uom,
    SUM(si.amount) AS total_revenue,
    SUM(si.product_vat) AS total_vat,
    SUM(si.discount_given) AS total_discount
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN products p ON si.product_code = p.product_code AND p.organization_id = s.organization_id
WHERE s.status = 'completed' AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, si.product_code, p.product_name, DATE(s.completed_at), s.branch_id, s.channel, si.uom
SQL);
    }

    public function down(): void
    {
        $legacy = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.fulfillment_meta, '$.legacy_import')), 'false') <> 'true'";

        DB::statement('DROP VIEW IF EXISTS v_sales_by_product');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_product AS
SELECT
    s.organization_id,
    si.product_code,
    p.product_name,
    DATE(s.created_at) AS sale_date,
    s.branch_id,
    s.channel,
    SUM(si.quantity) AS qty_sold,
    si.uom AS sell_uom,
    SUM(si.amount) AS total_revenue,
    SUM(si.product_vat) AS total_vat,
    SUM(si.discount_given) AS total_discount
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN products p ON si.product_code = p.product_code AND p.organization_id = s.organization_id
WHERE s.status = 'completed' AND {$legacy}
GROUP BY s.organization_id, si.product_code, p.product_name, DATE(s.created_at), s.branch_id, s.channel, si.uom
SQL);
    }
};
