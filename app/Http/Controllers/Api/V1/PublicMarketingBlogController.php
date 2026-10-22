<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformMarketingBlog;
use App\Services\Platform\MarketingBlogCacheService;
use App\Services\Platform\PlatformMarketingBlogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Public marketing blogs for the CentrixERP website Resources page.
 * Cached like Pitch Predictions: list 15 min, detail 6 hours, purged on publish.
 */
class PublicMarketingBlogController extends Controller
{
    public function __construct(
        protected PlatformMarketingBlogService $service,
        protected MarketingBlogCacheService $cache,
    ) {}

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
        if ($category !== '' && ! in_array($category, PlatformMarketingBlog::CATEGORIES, true)) {
            $category = '';
        }
        $perPage = min(50, max(1, (int) $request->query('per_page', 12)));
        $page = max(1, (int) $request->query('page', 1));

        $payload = $this->cache->rememberList($category, $page, $perPage, function () use ($category, $page, $perPage) {
            $query = PlatformMarketingBlog::query()->published();
            if ($category !== '') {
                $query->where('category', $category);
            }
            $total = (clone $query)->count();
            $rows = $query
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->forPage($page, $perPage)
                ->get();

            return [
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
            ];
        });

        return response()->json($payload);
    }

    public function show(string $slug)
    {
        if (! Schema::hasTable('platform_marketing_blogs')) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $key = $this->cache->detailCacheKey($slug);
        $cached = \Illuminate\Support\Facades\Cache::get($key);
        if (is_array($cached) && isset($cached['data'])) {
            return response()->json($cached);
        }

        $blog = PlatformMarketingBlog::query()
            ->published()
            ->where('slug', $slug)
            ->first();

        if (! $blog) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $payload = [
            'data' => $this->service->present($blog, public: true),
        ];
        \Illuminate\Support\Facades\Cache::put($key, $payload, now()->addHours(6));

        return response()->json($payload);
    }
}
