<?php

namespace App\Services\Background;

use App\Http\Controllers\Api\V1\ProductController;
use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Fetch product catalogue rows for background export without routing through HTTP.
 * Avoids internal sub-requests accidentally hitting products/{product_code} show routes.
 */
class ProductCatalogExportFetcher
{
    public function __construct(
        protected ProductController $products,
    ) {}

    /**
     * @param  array<string, mixed>  $searchParams
     * @param  callable(int, string): void|null  $onProgress
     * @return array{rows: list<array<string, mixed>>, row_count: int, truncated: bool}
     */
    public function fetchAll(
        array $searchParams,
        User $user,
        int $perPage = 500,
        int $maxRows = 10000,
        ?callable $onProgress = null,
        ?BackgroundTask $cancelTask = null,
    ): array {
        $perPage = min($perPage, 200);
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

            foreach ($rows as $row) {
                if (count($all) >= $maxRows) {
                    $truncated = true;
                    break 2;
                }
                if (is_array($row)) {
                    $all[] = $row;
                }
            }

            $lastPage = (int) ($payload['last_page'] ?? 1);
            if ($onProgress !== null && $lastPage > 0) {
                $progress = (int) floor(min(85, 10 + (($page / max(1, $lastPage)) * 70)));
                $onProgress($progress, 'Loading products…');
            }
            $page++;
        } while ($page <= $lastPage);

        return [
            'rows' => $all,
            'row_count' => count($all),
            'truncated' => $truncated,
        ];
    }
}
