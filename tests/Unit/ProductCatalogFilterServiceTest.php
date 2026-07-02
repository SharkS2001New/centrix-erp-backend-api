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

        $otherCategoryId = SubCategory::query()
            ->where('category_id', '!=', $subCategory->category_id)
            ->value('category_id');

        $query = Product::query()->whereNull('deleted_at');
        ProductCatalogFilterService::applyTaxonomyFilters(
            $query,
            $otherCategoryId ? (int) $otherCategoryId : (int) $subCategory->category_id,
            (int) $subCategory->id,
            null,
        );

        $this->assertSame(
            Product::query()->whereNull('deleted_at')->where('subcategory_id', $subCategory->id)->count(),
            $query->count(),
        );
    }
}
