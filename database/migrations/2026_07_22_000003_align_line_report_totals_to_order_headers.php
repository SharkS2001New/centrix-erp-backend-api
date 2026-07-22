<?php

use App\Services\Sales\CentrixSalesScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Line-based sales reports must tally with header-based reports and accounting.
 *
 * Canonical money:
 *   gross = sales.order_total (VAT-inclusive payable after order discount)
 *   vat   = sales.total_vat
 *   net   = order_total − total_vat
 *
 * Product/category/supplier views previously SUM(si.amount), which ignored
 * order_discount and could diverge from Daily Sales / journals.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = CentrixSalesScope::legacyExcludeSql('s');
        $statuses = CentrixSalesScope::reportPipelineStatusSql('s.status');
        $saleDate = CentrixSalesScope::reportSaleDateSql('s');
        $lineTotals = CentrixSalesScope::saleLineTotalsSubquerySql();
        $allocGross = CentrixSalesScope::allocatedLineGrossSql('si', 's', 'ls');
        $allocVat = CentrixSalesScope::allocatedLineVatSql('si', 's', 'ls');
        $allocDiscount = CentrixSalesScope::allocatedLineDiscountSql('si', 's', 'ls');

        DB::statement('DROP VIEW IF EXISTS v_sales_by_product');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_product AS
SELECT
    s.organization_id,
    si.product_code,
    COALESCE(p.product_name, si.product_code) AS product_name,
    {$saleDate} AS sale_date,
    s.branch_id,
    s.channel,
    SUM(si.quantity) AS qty_sold,
    si.uom AS sell_uom,
    MAX(uom.full_name) AS uom_name,
    MAX(uom.conversion_factor) AS conversion_factor,
    MAX(uom.small_packaging_label) AS small_packaging_label,
    MAX(uom.middle_packaging_label) AS middle_packaging_label,
    MAX(uom.middle_factor) AS middle_factor,
    MAX(uom.uom_type) AS uom_type,
    SUM({$allocGross}) AS total_revenue,
    SUM({$allocVat}) AS total_vat,
    SUM({$allocDiscount}) AS total_discount
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN {$lineTotals} ls ON ls.sale_id = si.sale_id
LEFT JOIN products p ON si.product_code = p.product_code AND p.organization_id = s.organization_id
LEFT JOIN uoms uom ON uom.id = p.unit_id
WHERE {$statuses} AND s.archived = 0 AND {$legacy}
GROUP BY s.organization_id, si.product_code, COALESCE(p.product_name, si.product_code), {$saleDate}, s.branch_id, s.channel, si.uom
SQL);

        DB::statement('DROP VIEW IF EXISTS v_sales_by_supplier');
        DB::statement(<<<SQL
CREATE VIEW v_sales_by_supplier AS
SELECT
    s.organization_id,
    COALESCE(sup.id, 0) AS supplier_id,
    COALESCE(sup.supplier_name, 'No supplier') AS supplier_name,
    COALESCE(sup.supplier_code, '') AS supplier_code,
    si.product_code,
    COALESCE(p.product_name, si.product_code) AS product_name,
    {$saleDate} AS sale_date,
    s.branch_id,
    s.channel,
    COUNT(DISTINCT s.id) AS order_count,
    1 AS products_sold,
    SUM(si.quantity) AS qty_sold,
    MAX(uom.full_name) AS uom_name,
    MAX(uom.conversion_factor) AS conversion_factor,
    MAX(uom.small_packaging_label) AS small_packaging_label,
    MAX(uom.middle_packaging_label) AS middle_packaging_label,
    MAX(uom.middle_factor) AS middle_factor,
    MAX(uom.uom_type) AS uom_type,
    SUM({$allocGross}) AS total_revenue,
    SUM({$allocVat}) AS total_vat,
    SUM({$allocDiscount}) AS total_discount
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN {$lineTotals} ls ON ls.sale_id = si.sale_id
LEFT JOIN products p ON si.product_code = p.product_code AND p.organization_id = s.organization_id
LEFT JOIN suppliers sup ON p.supplier_id = sup.id AND sup.organization_id = s.organization_id
LEFT JOIN uoms uom ON uom.id = p.unit_id
WHERE {$statuses} AND s.archived = 0 AND {$legacy}
GROUP BY
    s.organization_id, COALESCE(sup.id, 0), COALESCE(sup.supplier_name, 'No supplier'), COALESCE(sup.supplier_code, ''),
    si.product_code, COALESCE(p.product_name, si.product_code), {$saleDate}, s.branch_id, s.channel
SQL);

        DB::statement('DROP VIEW IF EXISTS v_category_sales');
        DB::statement(<<<SQL
CREATE VIEW v_category_sales AS
SELECT
    s.organization_id,
    {$saleDate} AS sale_date,
    s.branch_id,
    COALESCE(c.id, 0) AS category_id,
    COALESCE(c.category_name, 'Uncategorized') AS category_name,
    COALESCE(sc.id, 0) AS sub_category_id,
    COALESCE(sc.subcategory_name, 'Uncategorized') AS subcategory_name,
    si.product_code,
    COALESCE(p.product_name, si.product_code) AS product_name,
    SUM(si.quantity) AS qty_sold,
    MAX(uom.full_name) AS uom_name,
    MAX(uom.conversion_factor) AS conversion_factor,
    MAX(uom.small_packaging_label) AS small_packaging_label,
    MAX(uom.middle_packaging_label) AS middle_packaging_label,
    MAX(uom.middle_factor) AS middle_factor,
    MAX(uom.uom_type) AS uom_type,
    SUM({$allocGross}) AS revenue,
    SUM({$allocVat}) AS vat,
    SUM({$allocDiscount}) AS discounts
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN {$lineTotals} ls ON ls.sale_id = si.sale_id
LEFT JOIN products p ON si.product_code = p.product_code AND p.organization_id = s.organization_id
LEFT JOIN sub_categories sc ON p.subcategory_id = sc.id
LEFT JOIN categories c ON sc.category_id = c.id
LEFT JOIN uoms uom ON uom.id = p.unit_id
WHERE {$statuses} AND s.archived = 0 AND {$legacy}
GROUP BY
    s.organization_id, {$saleDate}, s.branch_id,
    COALESCE(c.id, 0), COALESCE(c.category_name, 'Uncategorized'),
    COALESCE(sc.id, 0), COALESCE(sc.subcategory_name, 'Uncategorized'),
    si.product_code, COALESCE(p.product_name, si.product_code)
SQL);

        DB::statement('DROP VIEW IF EXISTS v_discount_summary');
        DB::statement(<<<SQL
CREATE VIEW v_discount_summary AS
SELECT
    s.organization_id,
    {$saleDate} AS sale_date,
    s.branch_id,
    s.channel,
    COUNT(DISTINCT s.id) AS orders_with_discount,
    SUM({$allocDiscount}) AS total_discount,
    SUM({$allocGross}) AS net_line_sales
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN {$lineTotals} ls ON ls.sale_id = si.sale_id
WHERE {$statuses}
  AND s.archived = 0
  AND {$legacy}
  AND (
        COALESCE(si.discount_given, 0) > 0
     OR COALESCE(s.order_discount, 0) > 0
  )
GROUP BY s.organization_id, {$saleDate}, s.branch_id, s.channel
SQL);
    }

    public function down(): void
    {
        // Prior migrations redefine views if rolled back in order.
    }
};
