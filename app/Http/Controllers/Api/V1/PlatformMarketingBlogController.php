<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformMarketingBlog;
use App\Services\Platform\MarketingBlogCacheService;
use App\Services\Platform\MarketingBlogImageCompressor;
use App\Services\Platform\PlatformMarketingBlogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformMarketingBlogController extends Controller
{
    public function __construct(
        protected PlatformMarketingBlogService $service,
        protected MarketingBlogImageCompressor $images,
        protected MarketingBlogCacheService $cache,
    ) {}

    public function index(Request $request)
    {
        $status = $request->query('status');
        $category = $request->query('category');
        $q = trim((string) $request->query('q', ''));

        $blogs = PlatformMarketingBlog::query()
            ->with(['creator:id,full_name', 'publisher:id,full_name'])
            ->when(
                is_string($status) && $status !== '' && $status !== 'all',
                fn ($query) => $query->where('status', $status),
            )
            ->when(
                is_string($category) && $category !== '' && $category !== 'all',
                fn ($query) => $query->where('category', $category),
            )
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('title', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhere('excerpt', 'like', $like);
                });
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'title', 'slug', 'excerpt', 'cover_image_url', 'category', 'status', 'author_name', 'read_time', 'seo_title', 'seo_description', 'published_at', 'created_at', 'updated_at', 'created_by', 'published_by', 'updated_by']);

        return response()->json([
            'data' => $blogs
                ->map(fn (PlatformMarketingBlog $blog) => $this->service->presentListItem($blog))
                ->values()
                ->all(),
            'categories' => PlatformMarketingBlog::CATEGORIES,
        ]);
    }

    public function show(int $id)
    {
        $blog = PlatformMarketingBlog::query()
            ->with(['creator:id,full_name', 'publisher:id,full_name'])
            ->findOrFail($id);

        return response()->json([
            'data' => $this->service->present($blog),
            'categories' => PlatformMarketingBlog::CATEGORIES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $blog = $this->service->create($request->user(), $data);
        $blog->load(['creator:id,full_name', 'publisher:id,full_name']);

        return response()->json([
            'data' => $this->service->present($blog),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $blog = PlatformMarketingBlog::query()->findOrFail($id);
        $data = $this->validated($request, partial: true);
        $blog = $this->service->update($blog, $request->user(), $data);
        $blog->load(['creator:id,full_name', 'publisher:id,full_name']);

        return response()->json([
            'data' => $this->service->present($blog),
        ]);
    }

    public function publish(Request $request, int $id)
    {
        $blog = PlatformMarketingBlog::query()->findOrFail($id);
        $blog = $this->service->publish($blog, $request->user());
        $blog->load(['creator:id,full_name', 'publisher:id,full_name']);

        return response()->json([
            'data' => $this->service->present($blog),
            'message' => 'Published. The post is now visible on the CentrixERP Resources blog.',
        ]);
    }

    public function unpublish(Request $request, int $id)
    {
        $blog = PlatformMarketingBlog::query()->findOrFail($id);
        $blog = $this->service->unpublish($blog, $request->user());
        $blog->load(['creator:id,full_name', 'publisher:id,full_name']);

        return response()->json([
            'data' => $this->service->present($blog),
            'message' => 'Unpublished. The post is hidden from the public website.',
        ]);
    }

    public function destroy(int $id)
    {
        $blog = PlatformMarketingBlog::query()->findOrFail($id);
        $this->service->delete($blog);

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Upload + compress an image for the blog editor (Pitch Predictions style).
     * Returns { url } for insertion into the HTML body or cover field.
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:12288',
        ]);

        $path = $this->images->compressUploadedFile($request->file('image'));
        $url = $this->images->publicUrl($path);

        return response()->json([
            'url' => $url,
            'path' => $path,
        ], 201);
    }

    public function purgeCache(Request $request)
    {
        $slug = trim((string) $request->input('slug', ''));
        $this->cache->forgetLocal($slug !== '' ? $slug : null);
        $remote = $this->cache->purgeWebsite($slug !== '' ? $slug : null);

        return response()->json([
            'message' => 'Blog caches cleared.',
            'website' => $remote,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:200'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:220'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body' => [$required, 'string', 'max:500000'],
            'cover_image_url' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category' => ['sometimes', 'string', Rule::in(PlatformMarketingBlog::CATEGORIES)],
            'author_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:320'],
        ]);
    }
}
