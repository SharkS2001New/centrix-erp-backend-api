<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\SubCategory;
use App\Services\Catalog\ProductCatalogFilterService;
use Tests\TestCase;

class ProductCatalogFilterServiceTest extends TestCase
{
    public function test_subcategory_filter_takes_precedence_over_category(): void
    {
        $subCategory = SubCategory::query()->first();
        if ($subCategory === null) {
            $this->markTestSkipped('No subcategories in database.');
        }

        $otherSubCategory = SubCategory::query()
            ->where('id', '!=', $subCategory->id)
            ->first();

        $query = Product::query()->whereNull('deleted_at');
        ProductCatalogFilterService::applyTaxonomyFilters(
            $query,
            $otherSubCategory ? (int) $otherSubCategory->id : (int) $subCategory->id,
            (int) $subCategory->id,
            null,
        );

        $this->assertSame(
            Product::query()->whereNull('deleted_at')->where('subcategory_id', $subCategory->id)->count(),
            $query->count(),
        );
    }

    public function test_category_id_param_filters_by_subcategory(): void
    {
        $subCategory = SubCategory::query()->first();
        if ($subCategory === null) {
            $this->markTestSkipped('No subcategories in database.');
        }

        $query = Product::query()->whereNull('deleted_at');
        ProductCatalogFilterService::applyTaxonomyFilters(
            $query,
            (int) $subCategory->id,
            null,
            null,
        );

        $this->assertSame(
            Product::query()->whereNull('deleted_at')->where('subcategory_id', $subCategory->id)->count(),
            $query->count(),
        );
    }
}
