<?php

namespace App\Services\Background;

use App\Models\BackgroundTask;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

class InternalApiPaginator
{
    /** Background exports use larger pages to minimize in-process request count. */
    private const BACKGROUND_PER_PAGE = 500;

    /** Endpoints that validate per_page with max:200 (e.g. legacy archive sales). */
    public const API_VALIDATED_MAX_PER_PAGE = 200;

    /** @var list<string> */
    private const ALLOWED_PREFIXES = [
        '/products',
        '/customers',
        '/suppliers',
        '/employees',
        '/departments',
        '/positions',
        '/work-shifts',
        '/organization-holidays',
        '/employee-attendance',
        '/employee-leave-days',
        '/employee-leave-balances',
        '/payroll-runs',
        '/pay-periods',
        '/employee-overtime',
        '/employee-allowances',
        '/employee-deductions',
        '/employee-cash-advances',
        '/organization-kpis',
        '/damages',
        '/stock-receipts',
        '/inventory-transactions',
        '/stock-transactions',
        '/stock-take-lines',
        '/stock-take-sessions',
        '/stock-reservations',
        '/current-stock',
        '/categories',
        '/sub-categories',
        '/uoms',
        '/vats',
        '/expenses',
        '/expense-groups',
        '/price-history',
        '/retail-package-settings',
        '/lpo-mst',
        '/supplier-payments',
        '/supplier-return-documents',
        '/supplier-returns',
        '/sales',
        '/returns',
        '/vouchers',
        '/loyalty-cards',
        '/users',
        '/branches',
        '/routes',
        '/roles',
        '/audit-logs',
        '/payment-methods',
        '/journal-entries',
        '/customer-invoices',
        '/chart-of-accounts',
        '/fiscal-periods',
        '/account-mappings',
        '/accounting/',
        '/vehicles',
        '/drivers',
        '/dispatch-trips',
        '/route-schedules',
        '/pod-records',
        '/kra-responses',
        '/tills',
        '/reports/',
        '/legacy-archive/',
    ];

    public function __construct(
        protected InternalBackgroundRequest $internalRequest,
    ) {}

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
        ?int $perPage = null,
        ?int $maxRows = null,
        ?callable $onProgress = null,
        ?BackgroundTask $cancelTask = null,
    ): array {
        $normalizedPath = $this->assertAllowedPath($path);
        $searchParams = ReportExportSearchParams::sanitize($searchParams);
        $perPage = $perPage ?? (int) config('background.fetch_per_page', self::BACKGROUND_PER_PAGE);
        $maxRows = $maxRows ?? (int) config('background.max_fetch_rows', 50_000);
        $token = $this->createBackgroundToken($user);

        try {
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

                $payload = $this->internalRequest->get($normalizedPath, $query, $user, $token);
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
        } catch (\Throwable $e) {
            Log::warning('InternalApiPaginator fetch failed', [
                'path' => $normalizedPath,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Could not fetch report data: '.$e->getMessage(), 0, $e);
        } finally {
            $this->revokeToken($token);
        }
    }

    /**
     * Stream API pages to a callback without holding all rows in memory.
     *
     * @param  array<string, mixed>  $searchParams
     * @param  callable(list<array<string, mixed>>, int, int): void  $onPage
     * @return array{row_count: int, truncated: bool}
     */
    public function eachPage(
        string $path,
        array $searchParams,
        User $user,
        callable $onPage,
        ?int $perPage = null,
        ?int $maxRows = null,
        ?callable $onProgress = null,
        ?BackgroundTask $cancelTask = null,
    ): array {
        $normalizedPath = $this->assertAllowedPath($path);
        $searchParams = ReportExportSearchParams::sanitize($searchParams);
        $perPage = $perPage ?? (int) config('background.fetch_per_page', self::BACKGROUND_PER_PAGE);
        $maxRows = $maxRows ?? (int) config('background.max_export_rows', 100_000);
        $token = $this->createBackgroundToken($user);

        try {
            $rowCount = 0;
            $truncated = false;
            $page = 1;
            $lastPage = 1;

            do {
                if ($cancelTask !== null && $cancelTask->fresh()?->status === 'cancelled') {
                    throw new RuntimeException('Background task was cancelled.');
                }

                $query = array_merge($searchParams, [
                    'page' => $page,
                    'per_page' => $perPage,
                ]);

                $payload = $this->internalRequest->get($normalizedPath, $query, $user, $token);
                $rows = $payload['data'] ?? $payload['rows'] ?? [];
                if (! is_array($rows)) {
                    $rows = [];
                }

                $batch = [];
                foreach ($rows as $row) {
                    if ($rowCount >= $maxRows) {
                        $truncated = true;
                        break 2;
                    }
                    if (is_array($row)) {
                        $batch[] = $row;
                        $rowCount++;
                    }
                }

                if ($batch !== []) {
                    $lastPage = (int) ($payload['last_page'] ?? $payload['meta']['last_page'] ?? 1);
                    $onPage($batch, $page, $lastPage);
                }

                $lastPage = (int) ($payload['last_page'] ?? $payload['meta']['last_page'] ?? 1);
                if ($onProgress !== null && $lastPage > 0) {
                    $progress = (int) floor(min(85, 10 + (($page / max(1, $lastPage)) * 70)));
                    $onProgress($progress, 'Loading data…');
                }
                $page++;
            } while ($page <= $lastPage);

            return [
                'row_count' => $rowCount,
                'truncated' => $truncated,
            ];
        } catch (\Throwable $e) {
            Log::warning('InternalApiPaginator stream failed', [
                'path' => $normalizedPath,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Could not fetch report data: '.$e->getMessage(), 0, $e);
        } finally {
            $this->revokeToken($token);
        }
    }

    /**
     * Stream legacy archive pages merged into a Centrix report export.
     *
     * @param  array<string, mixed>  $searchParams
     * @param  callable(list<array<string, mixed>>, int, int): void  $onPage
     * @return array{row_count: int}
     */
    public function eachLegacyArchiveMerge(
        string $path,
        array $searchParams,
        User $user,
        callable $onPage,
        ?callable $onProgress = null,
        ?BackgroundTask $cancelTask = null,
    ): array {
        $normalizedPath = $this->assertAllowedPath($path);
        $searchParams = ReportExportSearchParams::sanitize($searchParams);
        $token = $this->createBackgroundToken($user);
        $rowCount = 0;
        $legacyPage = 1;
        $legacyLastPage = 1;

        try {
            do {
                if ($cancelTask !== null && $cancelTask->fresh()?->status === 'cancelled') {
                    throw new RuntimeException('Background task was cancelled.');
                }

                $query = array_merge($searchParams, [
                    'page' => 1,
                    'per_page' => self::BACKGROUND_PER_PAGE,
                    'include_legacy_archive' => 1,
                    'legacy_page' => $legacyPage,
                ]);

                $payload = $this->internalRequest->get($normalizedPath, $query, $user, $token);
                $legacyChunk = $payload['legacy_archive']['data'] ?? [];
                if (! is_array($legacyChunk)) {
                    $legacyChunk = [];
                }

                $batch = [];
                foreach ($legacyChunk as $row) {
                    if (is_array($row)) {
                        $batch[] = $row;
                        $rowCount++;
                    }
                }

                if ($batch !== []) {
                    $legacyLastPage = (int) ($payload['legacy_archive']['meta']['last_page'] ?? 1);
                    $onPage($batch, $legacyPage, $legacyLastPage);
                }

                $legacyLastPage = (int) ($payload['legacy_archive']['meta']['last_page'] ?? 1);
                if ($onProgress !== null && $legacyLastPage > 0) {
                    $progress = (int) floor(min(90, 75 + (($legacyPage / max(1, $legacyLastPage)) * 15)));
                    $onProgress($progress, 'Loading legacy archive data…');
                }
                $legacyPage++;
            } while ($legacyPage <= $legacyLastPage);

            return ['row_count' => $rowCount];
        } finally {
            $this->revokeToken($token);
        }
    }

    /**
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
        $legacyAll = [];
        $this->eachLegacyArchiveMerge(
            $path,
            $searchParams,
            $user,
            static function (array $batch) use (&$legacyAll): void {
                foreach ($batch as $row) {
                    $legacyAll[] = $row;
                }
            },
            $onProgress,
            $cancelTask,
        );

        return $legacyAll;
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
            self::API_VALIDATED_MAX_PER_PAGE,
            (int) config('background.max_export_rows', 100_000),
            $onProgress,
            $cancelTask,
        );
    }

    protected function createBackgroundToken(User $user): NewAccessToken
    {
        $token = $user->createToken('background-fetch', ['*'], now()->addMinutes(10));
        /** @var PersonalAccessToken|null $accessToken */
        $accessToken = $token->accessToken;

        if ($accessToken !== null && $user->organization_id) {
            $accessToken->forceFill([
                'organization_id' => (int) $user->organization_id,
            ])->save();
        }

        return $token;
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
