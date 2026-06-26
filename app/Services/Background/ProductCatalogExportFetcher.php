<?php

namespace App\Services\Background;

use App\Http\Controllers\Api\V1\ProductController;
use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Fetch product catalogue rows for background export without routing through HTTP.
 */
class ProductCatalogExportFetcher
{
    public function __construct(
        protected ProductController $products,
    ) {}

    /**
     * @param  array<string, mixed>  $searchParams
     * @param  callable(list<array<string, mixed>>, int, int): void  $onPage
     * @return array{row_count: int, truncated: bool}
     */
    public function eachPage(
        array $searchParams,
        User $user,
        callable $onPage,
        ?int $perPage = null,
        ?int $maxRows = null,
        ?callable $onProgress = null,
        ?BackgroundTask $cancelTask = null,
    ): array {
        $searchParams = ReportExportSearchParams::sanitize($searchParams);
        $perPage = min($perPage ?? (int) config('background.fetch_per_page', 500), 200);
        $maxRows = $maxRows ?? (int) config('background.max_export_rows', 100_000);

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

            $request = Request::create('/api/v1/products', 'GET', $query);
            $request->setUserResolver(static fn () => $user);

            $response = $this->products->index($request);
            if ($response->getStatusCode() >= 400) {
                throw new RuntimeException('Could not load products for export (HTTP '.$response->getStatusCode().').');
            }

            $payload = json_decode($response->getContent(), true);
            if (! is_array($payload)) {
                throw new RuntimeException('Could not load products for export (invalid response).');
            }

            $rows = $payload['data'] ?? [];
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
                $lastPage = (int) ($payload['last_page'] ?? 1);
                $onPage($batch, $page, $lastPage);
            }

            $lastPage = (int) ($payload['last_page'] ?? 1);
            if ($onProgress !== null && $lastPage > 0) {
                $progress = (int) floor(min(85, 10 + (($page / max(1, $lastPage)) * 70)));
                $onProgress($progress, 'Loading products…');
            }
            $page++;
        } while ($page <= $lastPage);

        return [
            'row_count' => $rowCount,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<string, mixed>  $searchParams
     * @return array{rows: list<array<string, mixed>>, row_count: int, truncated: bool}
     */
    public function fetchAll(
        array $searchParams,
        User $user,
        ?int $perPage = null,
        ?int $maxRows = null,
        ?callable $onProgress = null,
        ?BackgroundTask $cancelTask = null,
    ): array {
        $all = [];
        $result = $this->eachPage(
            $searchParams,
            $user,
            static function (array $batch) use (&$all): void {
                foreach ($batch as $row) {
                    $all[] = $row;
                }
            },
            $perPage,
            $maxRows,
            $onProgress,
            $cancelTask,
        );

        return [
            'rows' => $all,
            'row_count' => $result['row_count'],
            'truncated' => $result['truncated'],
        ];
    }
}
