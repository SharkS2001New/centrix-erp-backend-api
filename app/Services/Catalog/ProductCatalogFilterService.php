<?php

namespace App\Services\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProductCatalogFilterService
{
    /**
     * Resolve subcategory filter from request params.
     *
     * ERP clients label the control "Category" but populate it with subcategories,
     * so `category_id` is treated as a subcategory id for backwards compatibility.
     */
    public static function resolveSubcategoryFilterId(Request $request): ?int
    {
        foreach (['subcategory_id', 'sub_category_id', 'category_id'] as $param) {
            if ($request->filled($param)) {
                $id = (int) $request->input($param);
                if ($id > 0) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Narrow a product query by taxonomy.
     *
     * When both ids are supplied, explicit subcategory wins. Otherwise `categoryId`
     * is interpreted as a subcategory id (see resolveSubcategoryFilterId()).
     *
     * @param  Builder<\App\Models\Product>  $query
     */
    public static function applyTaxonomyFilters(
        Builder $query,
        ?int $categoryId = null,
        ?int $subcategoryId = null,
        ?int $supplierId = null,
    ): Builder {
        $resolvedSubcategoryId = $subcategoryId ?: $categoryId;
        if ($resolvedSubcategoryId) {
            $query->where('subcategory_id', $resolvedSubcategoryId);
        }

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        return $query;
    }
}
