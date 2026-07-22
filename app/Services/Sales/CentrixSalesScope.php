<?php

namespace App\Services\Sales;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Centrix operational sales metrics exclude materialized legacy imports.
 * Those rows exist only to support KRA legacy returns / credit notes.
 */
class CentrixSalesScope
{
    /**
     * Order statuses included in backoffice sales performance reports.
     * Excludes draft/held/cancelled/expired so reports can trace work in progress
     * (booked → completed, including unpaid / partial payments).
     *
     * @return list<string>
     */
    public static function reportPipelineStatuses(): array
    {
        return [
            'booked',
            'pending',
            'unpaid',
            'pending_payment',
            'paid',
            'processed',
            'delivered',
            'completed',
        ];
    }

    /** SQL: `s.status IN ('booked', …)` */
    public static function reportPipelineStatusSql(string $column = 's.status'): string
    {
        $list = implode(', ', array_map(
            static fn (string $status): string => "'".str_replace("'", "''", $status)."'",
            self::reportPipelineStatuses(),
        ));

        return "{$column} IN ({$list})";
    }

    /**
     * Report date bucket: order placed / booked date.
     * Use created_at (not completed_at) so pipeline orders stay on the day the
     * salesperson placed them — matching the sales list "Placed date" filter.
     */
    public static function reportSaleDateSql(string $alias = 's'): string
    {
        return "DATE({$alias}.created_at)";
    }

    /**
     * @param  EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @return EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder
     */
    public static function excludeLegacyMaterialized(EloquentBuilder|QueryBuilder $query, ?string $tableAlias = null): EloquentBuilder|QueryBuilder
    {
        $prefix = $tableAlias ? "{$tableAlias}." : '';

        return $query->where(function ($sub) use ($prefix) {
            $sub->whereNull("{$prefix}fulfillment_meta")
                ->orWhereNull("{$prefix}fulfillment_meta->legacy_import")
                ->orWhere("{$prefix}fulfillment_meta->legacy_import", '!=', true);
        });
    }

    /** SQL predicate for MySQL views (e.g. alias `s`). */
    public static function legacyExcludeSql(string $alias = 's'): string
    {
        $column = "{$alias}.fulfillment_meta";

        return "({$column} IS NULL OR JSON_EXTRACT({$column}, '$.legacy_import') IS NULL OR JSON_EXTRACT({$column}, '$.legacy_import') = false OR JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.legacy_import')) = 'false')";
    }

    /**
     * Restrict a query builder to sales pipeline statuses used by reports.
     *
     * @param  EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @return EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder
     */
    public static function whereReportPipelineStatus(EloquentBuilder|QueryBuilder $query, string $column = 'status'): EloquentBuilder|QueryBuilder
    {
        return $query->whereIn($column, self::reportPipelineStatuses());
    }

    /**
     * Subquery that totals raw line gross/VAT per sale for header allocation.
     *
     * Line reports must allocate `order_total` / `total_vat` across lines so
     * SUM(allocated) = SUM(headers). Raw `si.amount` ignores `order_discount`.
     *
     * Returns the parenthesized SELECT only (no alias) so callers can
     * `JOIN (... ) ls ON …` or `join(DB::raw('(...) as ls'), …)`.
     */
    public static function saleLineTotalsSubquerySql(): string
    {
        return <<<'SQL'
(
    SELECT
        sale_id,
        SUM(amount) AS line_gross,
        SUM(product_vat) AS line_vat
    FROM sale_items
    GROUP BY sale_id
)
SQL;
    }

    /** @deprecated use saleLineTotalsSubquerySql() */
    public static function saleLineTotalsJoinSql(string $alias = 'ls'): string
    {
        return self::saleLineTotalsSubquerySql().' '.$alias;
    }

    /**
     * Line share of header gross (VAT-inclusive payable after order discount).
     *
     * @param  string  $siAlias  sale_items alias
     * @param  string  $salesAlias  sales alias
     * @param  string  $lsAlias  line totals subquery alias from saleLineTotalsJoinSql()
     */
    public static function allocatedLineGrossSql(
        string $siAlias = 'si',
        string $salesAlias = 's',
        string $lsAlias = 'ls',
    ): string {
        return "CASE WHEN {$lsAlias}.line_gross > 0 THEN ({$siAlias}.amount * ({$salesAlias}.order_total / {$lsAlias}.line_gross)) ELSE 0 END";
    }

    /**
     * Line share of header VAT (matches sales.total_vat after allocation).
     */
    public static function allocatedLineVatSql(
        string $siAlias = 'si',
        string $salesAlias = 's',
        string $lsAlias = 'ls',
    ): string {
        return <<<SQL
CASE
    WHEN {$lsAlias}.line_vat > 0 THEN ({$siAlias}.product_vat * ({$salesAlias}.total_vat / {$lsAlias}.line_vat))
    WHEN {$lsAlias}.line_gross > 0 THEN ({$salesAlias}.total_vat * ({$siAlias}.amount / {$lsAlias}.line_gross))
    ELSE 0
END
SQL;
    }

    /**
     * Line discount + proportional share of order-level discount.
     */
    public static function allocatedLineDiscountSql(
        string $siAlias = 'si',
        string $salesAlias = 's',
        string $lsAlias = 'ls',
    ): string {
        return <<<SQL
(
    COALESCE({$siAlias}.discount_given, 0)
    + CASE
        WHEN {$lsAlias}.line_gross > 0 THEN ({$siAlias}.amount * (COALESCE({$salesAlias}.order_discount, 0) / {$lsAlias}.line_gross))
        ELSE 0
      END
)
SQL;
    }

    /**
     * Scale header VAT when order discount reduces payable gross.
     * Keeps net = order_total − total_vat consistent with inclusive pricing.
     */
    public static function scaleVatForOrderDiscount(float $lineGross, float $lineVat, float $orderDiscount): array
    {
        $lineGross = max(0, round($lineGross, 2));
        $lineVat = max(0, round($lineVat, 2));
        $orderDiscount = min(max(0, round($orderDiscount, 2)), $lineGross);
        $orderTotal = max(0, round($lineGross - $orderDiscount, 2));

        if ($lineGross <= 0 || $orderDiscount <= 0) {
            return [
                'order_total' => $orderTotal,
                'total_vat' => $lineVat,
                'order_discount' => $orderDiscount,
            ];
        }

        return [
            'order_total' => $orderTotal,
            'total_vat' => round($lineVat * ($orderTotal / $lineGross), 2),
            'order_discount' => $orderDiscount,
        ];
    }
}
