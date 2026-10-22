<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformMarketingBlog;
use App\Models\PlatformMarketingBlogCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PlatformMarketingBlogCategoryController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => PlatformMarketingBlog::categoryOptions(),
        ]);
    }

    public function store(Request $request)
    {
        if (! Schema::hasTable('platform_marketing_blog_categories')) {
            throw ValidationException::withMessages([
                'label' => ['Blog categories table is not available. Run migrations first.'],
            ]);
        }

        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
        ]);

        $label = trim($data['label']);
        if ($label === '') {
            throw ValidationException::withMessages([
                'label' => ['Category name is required.'],
            ]);
        }

        $slug = PlatformMarketingBlogCategory::uniqueSlug($label);
        $maxOrder = (int) PlatformMarketingBlogCategory::query()->max('sort_order');

        $row = PlatformMarketingBlogCategory::query()->create([
            'slug' => $slug,
            'label' => $label,
            'sort_order' => $maxOrder + 10,
        ]);

        return response()->json([
            'data' => ['id' => $row->slug, 'label' => $row->label],
            'categories' => PlatformMarketingBlog::categoryOptions(),
            'message' => 'Category created.',
        ], 201);
    }
}
