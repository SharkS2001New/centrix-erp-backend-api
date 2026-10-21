<?php

namespace App\Services\Platform;

use App\Models\PlatformMarketingBlog;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformMarketingBlogService
{
    public function create(User $actor, array $data): PlatformMarketingBlog
    {
        $slug = $this->uniqueSlug($data['slug'] ?? $data['title']);

        return PlatformMarketingBlog::query()->create([
            'title' => $data['title'],
            'slug' => $slug,
            'excerpt' => $data['excerpt'] ?? null,
            'body' => $data['body'],
            'cover_image_url' => $data['cover_image_url'] ?? null,
            'category' => $data['category'] ?? 'general',
            'status' => PlatformMarketingBlog::STATUS_DRAFT,
            'author_name' => $data['author_name'] ?? null,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    public function update(PlatformMarketingBlog $blog, User $actor, array $data): PlatformMarketingBlog
    {
        if (array_key_exists('title', $data)) {
            $blog->title = $data['title'];
        }
        if (array_key_exists('slug', $data) && is_string($data['slug']) && $data['slug'] !== '') {
            $blog->slug = $this->uniqueSlug($data['slug'], $blog->id);
        } elseif (array_key_exists('title', $data) && $blog->isDraft()) {
            $blog->slug = $this->uniqueSlug($data['title'], $blog->id);
        }
        foreach (['excerpt', 'body', 'cover_image_url', 'category', 'author_name', 'seo_title', 'seo_description'] as $key) {
            if (array_key_exists($key, $data)) {
                $blog->{$key} = $data[$key];
            }
        }
        $blog->updated_by = $actor->id;
        $blog->save();

        return $blog->fresh();
    }

    public function publish(PlatformMarketingBlog $blog, User $actor): PlatformMarketingBlog
    {
        if (! trim((string) $blog->title) || ! trim((string) $blog->body)) {
            throw ValidationException::withMessages([
                'body' => ['Title and body are required before publishing.'],
            ]);
        }

        $blog->status = PlatformMarketingBlog::STATUS_PUBLISHED;
        $blog->published_at = $blog->published_at ?? now();
        $blog->published_by = $actor->id;
        $blog->updated_by = $actor->id;
        $blog->save();

        return $blog->fresh();
    }

    public function unpublish(PlatformMarketingBlog $blog, User $actor): PlatformMarketingBlog
    {
        $blog->status = PlatformMarketingBlog::STATUS_DRAFT;
        $blog->updated_by = $actor->id;
        $blog->save();

        return $blog->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PlatformMarketingBlog $blog, bool $public = false): array
    {
        $payload = [
            'id' => $blog->id,
            'title' => $blog->title,
            'slug' => $blog->slug,
            'excerpt' => $blog->excerpt,
            'body' => $blog->body,
            'cover_image_url' => $blog->cover_image_url,
            'category' => $blog->category,
            'author_name' => $blog->author_name ?: 'CentrixERP',
            'seo_title' => $blog->seo_title,
            'seo_description' => $blog->seo_description,
            'status' => $blog->status,
            'published_at' => optional($blog->published_at)?->toIso8601String(),
            'updated_at' => optional($blog->updated_at)?->toIso8601String(),
            'created_at' => optional($blog->created_at)?->toIso8601String(),
        ];

        if (! $public) {
            $payload['created_by'] = $blog->created_by;
            $payload['published_by'] = $blog->published_by;
            $payload['updated_by'] = $blog->updated_by;
            $payload['creator_name'] = $blog->creator?->full_name;
            $payload['publisher_name'] = $blog->publisher?->full_name;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentListItem(PlatformMarketingBlog $blog, bool $public = false): array
    {
        $full = $this->present($blog, $public);
        unset($full['body']);

        return $full;
    }

    public function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($source, 180, '')) ?: 'post';
        $slug = $base;
        $i = 2;
        while (
            PlatformMarketingBlog::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
