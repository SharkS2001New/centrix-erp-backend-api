<?php

namespace App\Services\Catalog;

use App\Models\SubCategory;
use Illuminate\Database\Eloquent\Builder;

class ProductCatalogFilterService
{
    /**
     * Narrow a product query by taxonomy. Subcategory wins over category when both are set.
     *
     * @param  Builder<\App\Models\Product>  $query
     */
    public static function applyTaxonomyFilters(
        Builder $query,
        ?int $categoryId = null,
        ?int $subcategoryId = null,
        ?int $supplierId = null,
    ): Builder {
        if ($subcategoryId) {
            $query->where('subcategory_id', $subcategoryId);
        } elseif ($categoryId) {
            $subIds = SubCategory::query()
                ->where('category_id', $categoryId)
                ->pluck('id');
            if ($subIds->isEmpty()) {
                $query->whereRaw('0 = 1');
            } else {
                $query->whereIn('subcategory_id', $subIds);
            }
        }

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        return $query;
    }
}
