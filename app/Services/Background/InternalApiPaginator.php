<?php

namespace App\Services\Background;

use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

class InternalApiPaginator
{
    /** @var list<string> */
    private const ALLOWED_PREFIXES = [
        '/products',
        '/damages',
        '/stock-receipts',
        '/inventory-transactions',
        '/stock-transactions',
        '/stock-take-lines',
        '/reports/',
        '/legacy-archive/',
        '/employee-attendance',
        '/sales',
        '/customers',
        '/suppliers',
        '/lpo-mst',
    ];

    public function assertAllowedPath(string $path): string
    {
        $normalized = '/'.ltrim($path, '/');

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return $normalized;
            }
        }

        throw new InvalidArgumentException('API path is not allowed for background fetch.');
    }

    /**
     * @param  array<string, mixed>  $searchParams
     * @return array{rows: list<array<string, mixed>>, row_count: int, truncated: bool}
     */
    public function fetchAll(
        string $path,
        array $searchParams,
        User $user,
        int $perPage = 200,
        int $maxRows = 10000,
        ?callable $onProgress = null,
        ?BackgroundTask $cancelTask = null,
    ): array {
        $normalizedPath = $this->assertAllowedPath($path);
        $token = $user->createToken('background-fetch', ['*'], now()->addMinutes(10));
        $plainToken = $token->plainTextToken;

        try {
            $baseUrl = rtrim((string) config('app.url'), '/').'/api/v1';
            $all = [];
            $page = 1;
            $lastPage = 1;
            $truncated = false;

            do {
                if ($cancelTask !== null && $cancelTask->fresh()?->status === 'cancelled') {
                    throw new RuntimeException('Background task was cancelled.');
                }

                $query = array_merge($searchParams, [
                    'page' => $page,
                    'per_page' => $perPage,
                ]);

                $response = Http::withToken($plainToken)
                    ->acceptJson()
                    ->timeout(120)
                    ->get($baseUrl.$normalizedPath, $query);

                if (! $response->successful()) {
                    throw new RuntimeException(
                        'Paginated fetch failed on page '.$page.' (HTTP '.$response->status().').'
                    );
                }

                $payload = $response->json();
                $rows = $payload['data'] ?? $payload['rows'] ?? [];
                if (! is_array($rows)) {
                    $rows = [];
                }

                foreach ($rows as $row) {
                    if (count($all) >= $maxRows) {
                        $truncated = true;
                        break 2;
                    }
                    if (is_array($row)) {
                        $all[] = $row;
                    }
                }

                $lastPage = (int) ($payload['last_page'] ?? $payload['meta']['last_page'] ?? 1);
                if ($onProgress !== null && $lastPage > 0) {
                    $progress = (int) floor(min(85, 10 + (($page / max(1, $lastPage)) * 70)));
                    $onProgress($progress, 'Loading data…');
                }
                $page++;
            } while ($page <= $lastPage);

            return [
                'rows' => $all,
                'row_count' => count($all),
                'truncated' => $truncated,
            ];
        } catch (ConnectionException $e) {
            Log::warning('InternalApiPaginator connection failed', [
                'path' => $normalizedPath,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Could not reach the API while fetching paginated data.');
        } finally {
            $this->revokeToken($token);
        }
    }

    /**
     * Fetch legacy archive rows merged into a Centrix report export.
     *
     * @param  array<string, mixed>  $searchParams
     * @return list<array<string, mixed>>
     */
    public function fetchLegacyArchiveMerge(
        string $path,
        array $searchParams,
        User $user,
        ?callable $onProgress = null,
        ?BackgroundTask $cancelTask = null,
    ): array {
        $normalizedPath = $this->assertAllowedPath($path);
        $token = $user->createToken('background-fetch', ['*'], now()->addMinutes(10));
        $plainToken = $token->plainTextToken;
        $legacyAll = [];
        $legacyPage = 1;
        $legacyLastPage = 1;

        try {
            $baseUrl = rtrim((string) config('app.url'), '/').'/api/v1';

            do {
                if ($cancelTask !== null && $cancelTask->fresh()?->status === 'cancelled') {
                    throw new RuntimeException('Background task was cancelled.');
                }

                $query = array_merge($searchParams, [
                    'page' => 1,
                    'per_page' => 200,
                    'include_legacy_archive' => 1,
                    'legacy_page' => $legacyPage,
                ]);

                $response = Http::withToken($plainToken)
                    ->acceptJson()
                    ->timeout(120)
                    ->get($baseUrl.$normalizedPath, $query);

                if (! $response->successful()) {
                    throw new RuntimeException(
                        'Legacy archive fetch failed on page '.$legacyPage.' (HTTP '.$response->status().').'
                    );
                }

                $payload = $response->json();
                $legacyChunk = $payload['legacy_archive']['data'] ?? [];
                if (! is_array($legacyChunk)) {
                    $legacyChunk = [];
                }
                foreach ($legacyChunk as $row) {
                    if (is_array($row)) {
                        $legacyAll[] = $row;
                    }
                }

                $legacyLastPage = (int) ($payload['legacy_archive']['meta']['last_page'] ?? 1);
                if ($onProgress !== null && $legacyLastPage > 0) {
                    $progress = (int) floor(min(90, 75 + (($legacyPage / max(1, $legacyLastPage)) * 15)));
                    $onProgress($progress, 'Loading legacy archive data…');
                }
                $legacyPage++;
            } while ($legacyPage <= $legacyLastPage);

            return $legacyAll;
        } finally {
            $this->revokeToken($token);
        }
    }

    /**
     * @param  array<string, mixed>  $searchParams
     * @return array{rows: list<array<string, mixed>>, row_count: int, truncated: bool}
     */
    public function fetchLegacyArchiveSales(
        array $searchParams,
        User $user,
        ?callable $onProgress = null,
        ?BackgroundTask $cancelTask = null,
    ): array {
        return $this->fetchAll(
            '/reports/legacy-archive/sales',
            $searchParams,
            $user,
            200,
            10000,
            $onProgress,
            $cancelTask,
        );
    }

    protected function revokeToken(NewAccessToken $token): void
    {
        try {
            $token->accessToken?->delete();
        } catch (\Throwable) {
            // Best-effort cleanup for short-lived internal tokens.
        }
    }
}
