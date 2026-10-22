<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Public marketing-blog cache (Pitch Predictions style):
 * - API list/detail keys with version bump
 * - Optional purge of the marketing website disk cache after publish
 */
class MarketingBlogCacheService
{
    public const INDEX_VERSION_KEY = 'marketing_blogs_index_version';

    public function indexVersion(): string
    {
        return (string) (Cache::get(self::INDEX_VERSION_KEY) ?: '1');
    }

    public function bumpIndexVersion(): void
    {
        Cache::forever(self::INDEX_VERSION_KEY, (string) round(microtime(true) * 1000));
    }

    public function listCacheKey(string $category, int $page, int $perPage): string
    {
        $cat = $category !== '' ? $category : 'all';

        return 'api_marketing_blogs_'.$this->indexVersion().'_'.$cat.'_p'.$page.'_n'.$perPage;
    }

    public function detailCacheKey(string $slug): string
    {
        return 'api_marketing_blog_'.md5($slug).'_v3';
    }

    public function rememberList(string $category, int $page, int $perPage, callable $callback): mixed
    {
        return Cache::remember(
            $this->listCacheKey($category, $page, $perPage),
            now()->addMinutes(15),
            $callback,
        );
    }

    public function rememberDetail(string $slug, callable $callback): mixed
    {
        return Cache::remember(
            $this->detailCacheKey($slug),
            now()->addHours(6),
            $callback,
        );
    }

    public function forgetLocal(?string ...$slugs): void
    {
        foreach ($slugs as $slug) {
            $slug = trim((string) $slug);
            if ($slug !== '') {
                Cache::forget($this->detailCacheKey($slug));
            }
        }
        $this->bumpIndexVersion();
    }

    /**
     * Purge marketing-site disk caches (list + optional slug).
     *
     * @return array{ok: bool, message: string, results: list<array<string, mixed>>}
     */
    public function purgeWebsite(?string $slug = null): array
    {
        $base = rtrim((string) config('marketing_blog.website_url', ''), '/');
        $key = (string) config('marketing_blog.cache_clear_key', '');

        if ($base === '' || strlen($key) < 16) {
            return [
                'ok' => true,
                'message' => 'Website cache purge skipped (MARKETING_BLOG_WEBSITE_URL / MARKETING_BLOG_CACHE_CLEAR_KEY not set).',
                'results' => [],
            ];
        }

        $results = [];
        $ok = true;

        $list = $this->hitClear($base.'/api/clear-blog-list-cache', $key, 'blog list');
        $results[] = $list;
        $ok = $ok && $list['ok'];

        if ($slug) {
            $detail = $this->hitClear(
                $base.'/api/clear-blog-cache/'.rawurlencode($slug),
                $key,
                'blog '.$slug,
            );
            $results[] = $detail;
            $ok = $ok && $detail['ok'];
        }

        return [
            'ok' => $ok,
            'message' => $ok ? 'Website blog cache cleared.' : 'Some website cache clears failed.',
            'results' => $results,
        ];
    }

    /**
     * Forget API keys then purge the website after the response is sent.
     */
    public function purgeAfterChange(?string $slug = null): void
    {
        $this->forgetLocal($slug);

        $websiteUrl = (string) config('marketing_blog.website_url', '');
        $key = (string) config('marketing_blog.cache_clear_key', '');
        if ($websiteUrl === '' || strlen($key) < 16) {
            return;
        }

        dispatch(function () use ($slug) {
            $this->purgeWebsite($slug);
        })->afterResponse();
    }

    /**
     * @return array{ok: bool, label: string, status?: int|null, error?: string}
     */
    protected function hitClear(string $url, string $key, string $label): array
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'X-Blog-Cache-Key' => $key,
                    'Accept' => 'application/json',
                ])
                ->post($url);

            return [
                'ok' => $response->successful(),
                'label' => $label,
                'status' => $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Marketing blog website cache purge failed', [
                'label' => $label,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'label' => $label,
                'error' => $e->getMessage(),
            ];
        }
    }
}
