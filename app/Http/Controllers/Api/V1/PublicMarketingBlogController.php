<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformMarketingBlog;
use App\Services\Platform\PlatformMarketingBlogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Public marketing blogs for the CentrixERP website Resources page.
 * Content is whatever platform admins publish under Platform → Blogs.
 */
class PublicMarketingBlogController extends Controller
{
    public function __construct(protected PlatformMarketingBlogService $service) {}

    public function index(Request $request)
    {
        if (! Schema::hasTable('platform_marketing_blogs')) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'page' => 1, 'per_page' => 12],
                'categories' => PlatformMarketingBlog::CATEGORIES,
            ]);
        }

        $category = trim((string) $request->query('category', ''));
        $perPage = min(50, max(1, (int) $request->query('per_page', 12)));
        $page = max(1, (int) $request->query('page', 1));

        $query = PlatformMarketingBlog::query()->published();

        if ($category !== '' && in_array($category, PlatformMarketingBlog::CATEGORIES, true)) {
            $query->where('category', $category);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'data' => $rows
                ->map(fn (PlatformMarketingBlog $blog) => $this->service->presentListItem($blog, public: true))
                ->values()
                ->all(),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ],
            'categories' => PlatformMarketingBlog::CATEGORIES,
            'source' => 'platform_marketing_blogs',
        ]);
    }

    public function show(string $slug)
    {
        if (! Schema::hasTable('platform_marketing_blogs')) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $blog = PlatformMarketingBlog::query()
            ->published()
            ->where('slug', $slug)
            ->first();

        if (! $blog) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'data' => $this->service->present($blog, public: true),
        ]);
    }
}
