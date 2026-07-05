<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Centrix operational list search (products, sales, customers).
 *
 * Apply only after the caller has scoped the query to the authenticated tenant:
 * organization_id, branch limits, and (for sales) CentrixSalesScope::excludeLegacyMaterialized().
 *
 * Does not query LightStores legacy archive databases — use legacy archive APIs for that data.
 * Uses substring matching (%term%) on Centrix tables; user-supplied % and _ are escaped.
 */
class SqlLikeSearch
{
    public static function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * @param  EloquentBuilder<mixed>|QueryBuilder  $query
     */
    public static function applyProductSearch(
        EloquentBuilder|QueryBuilder $query,
        string $term,
        string $codeColumn = 'products.product_code',
        string $nameColumn = 'products.product_name',
    ): void {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.self::escape($term).'%';

        $query->where(function ($inner) use ($like, $codeColumn, $nameColumn) {
            $inner->where($codeColumn, 'like', $like)
                ->orWhere($nameColumn, 'like', $like);
        });
    }

    /**
     * @param  EloquentBuilder<mixed>|QueryBuilder  $query
     */
    public static function applySalesOrderSearch(
        EloquentBuilder|QueryBuilder $query,
        string $term,
        bool $includeCustomerRelation = false,
    ): void {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.self::escape($term).'%';

        $query->where(function ($sub) use ($like, $includeCustomerRelation) {
            $sub->whereRaw('CAST(sales.order_num AS CHAR) LIKE ?', [$like])
                ->orWhere('sales.customer_name_override', 'like', $like)
                ->orWhereRaw('CAST(sales.customer_num AS CHAR) LIKE ?', [$like]);

            if ($includeCustomerRelation) {
                $sub->orWhereHas('customer', fn ($customer) => $customer->where('customer_name', 'like', $like));
            }
        });
    }

    /**
     * @param  EloquentBuilder<mixed>|QueryBuilder  $query
     */
    public static function applyCustomerSearch(EloquentBuilder|QueryBuilder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.self::escape($term).'%';

        $query->where(function ($inner) use ($like) {
            $inner->where('customer_name', 'like', $like)
                ->orWhere('phone_number', 'like', $like)
                ->orWhere('additional_phone', 'like', $like)
                ->orWhereRaw('CAST(customer_num AS CHAR) LIKE ?', [$like])
                ->orWhere('town', 'like', $like)
                ->orWhere('kra_pin', 'like', $like);
        });
    }

    /**
     * @param  EloquentBuilder<mixed>|QueryBuilder  $query
     * @param  list<string>  $columns
     */
    public static function applyOrColumnsSearch(
        EloquentBuilder|QueryBuilder $query,
        string $term,
        array $columns,
    ): void {
        $term = trim($term);
        if ($term === '' || $columns === []) {
            return;
        }

        $like = '%'.self::escape($term).'%';

        $query->where(function ($inner) use ($like, $columns) {
            foreach ($columns as $i => $col) {
                $method = $i === 0 ? 'where' : 'orWhere';
                $inner->{$method}($col, 'like', $like);
            }
        });
    }
}
