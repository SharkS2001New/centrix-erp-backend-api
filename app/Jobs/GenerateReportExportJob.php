<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Background\InternalApiPaginator;
use App\Services\Background\ReportExportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class GenerateReportExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RunsBackgroundTaskOnce;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(
        BackgroundTaskService $tasks,
        InternalApiPaginator $paginator,
        ReportExportService $exporter,
    ): void {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($this->shouldSkipBackgroundTask($task)) {
            return;
        }

        $tasks->markRunning($task);
        $tasks->updateProgress($task, 2, 'Started fetching…');

        try {
            $user = User::query()->find($task->user_id);
            if ($user === null) {
                throw new InvalidArgumentException('User not found for report export task.');
            }

            $payload = is_array($task->payload) ? $task->payload : [];
            $format = (string) ($payload['format'] ?? 'xlsx');
            $columns = $payload['columns'] ?? [];
            $meta = $payload['meta'] ?? [];
            $footerRow = $payload['footer_row'] ?? null;
            $filename = (string) ($payload['filename'] ?? 'report');
            $source = (string) ($payload['source'] ?? 'api');

            if (! is_array($columns) || count($columns) === 0) {
                throw new InvalidArgumentException('Export columns are required.');
            }

            $onProgress = function (int $progress, string $message) use ($tasks, $task): void {
                $tasks->updateProgress($task, $progress, $message);
            };

            $rows = $this->resolveRows($payload, $source, $paginator, $user, $task, $tasks, $onProgress);
            $tasks->assertNotCancelled($task);
            $tasks->updateProgress($task, 88, 'Generating file…');

            $file = $exporter->generate(
                $format,
                $filename,
                is_array($meta) ? $meta : [],
                $columns,
                $rows,
                is_array($footerRow) ? $footerRow : null,
                (int) $user->organization_id,
                $task->id,
                function (int $progress, string $message) use ($tasks, $task): void {
                    $tasks->updateProgress($task, $progress, $message);
                },
            );

            $tasks->updateProgress($task, 98, 'Almost done…');
            $tasks->markCompleted($task, array_merge($file, [
                'truncated' => (bool) ($payload['truncated'] ?? false),
            ]));
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'GenerateReportExportJob');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(int, string): void  $onProgress
     * @return list<array<string, mixed>>
     */
    protected function resolveRows(
        array $payload,
        string $source,
        InternalApiPaginator $paginator,
        User $user,
        BackgroundTask $task,
        BackgroundTaskService $tasks,
        callable $onProgress,
    ): array {
        if ($source === 'inline_rows') {
            $rows = $payload['rows'] ?? [];
            if (! is_array($rows)) {
                return [];
            }

            return array_values(array_filter($rows, 'is_array'));
        }

        if ($source === 'legacy_archive_sales') {
            $searchParams = $payload['search_params'] ?? [];
            if (! is_array($searchParams)) {
                $searchParams = [];
            }
            $onProgress(8, 'Fetching legacy archive sales…');
            $result = $paginator->fetchLegacyArchiveSales($searchParams, $user, $onProgress, $task);

            return $this->mapLegacyArchiveSalesRows($result['rows']);
        }

        if ($source === 'product_catalog') {
            $searchParams = $payload['search_params'] ?? [];
            if (! is_array($searchParams)) {
                $searchParams = [];
            }
            $onProgress(8, 'Fetching products…');
            $result = $paginator->fetchAll('/products', $searchParams, $user, 500, 10000, $onProgress, $task);

            return $this->mapProductCatalogRows($result['rows']);
        }

        $path = (string) ($payload['path'] ?? '');
        if ($path === '') {
            throw new InvalidArgumentException('API path is required for report export.');
        }

        $searchParams = $payload['search_params'] ?? [];
        if (! is_array($searchParams)) {
            $searchParams = [];
        }

        $onProgress(8, 'Fetching report data…');
        $result = $paginator->fetchAll($path, $searchParams, $user, 500, 10000, $onProgress, $task);
        $rows = $result['rows'];

        if (! empty($payload['legacy_merge']['enabled'])) {
            $tasks->updateProgress($task, 72, 'Loading legacy archive data…');
            $legacyRows = $paginator->fetchLegacyArchiveMerge(
                $path,
                $searchParams,
                $user,
                $onProgress,
                $task,
            );
            $rows = array_merge($rows, $legacyRows);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    protected function mapProductCatalogRows(array $products): array
    {
        return array_map(static function (array $product): array {
            $discount = ($product['discount_type'] ?? '') === 'fixed'
                ? ($product['discount_value'] ?? '')
                : ($product['discount_percentage'] ?? '');

            return [
                'product_code' => $product['product_code'] ?? '',
                'product_name' => $product['product_name'] ?? '',
                'category_name' => $product['category_name'] ?? '',
                'subcategory_name' => $product['subcategory_name'] ?? '',
                'unit_price' => $product['unit_price'] ?? '',
                'last_cost_price' => $product['last_cost_price'] ?? '',
                'discount' => $discount,
                'shop_qty' => $product['shop_qty'] ?? '',
                'store_qty' => $product['store_qty'] ?? '',
                'uom_label' => $product['uom_label'] ?? '',
                'supplier_name' => $product['supplier_name'] ?? '',
                'vat_treatment' => $product['vat_treatment'] ?? '',
                'pricing' => $product['pricing'] ?? '',
                'is_active' => ! empty($product['is_active']) ? 'Yes' : 'No',
            ];
        }, $products);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function mapLegacyArchiveSalesRows(array $rows): array
    {
        $channelLabels = [
            'pos' => 'POS',
            'mobile' => 'MOBILE',
            'debtor' => 'DEBTOR / credit',
        ];

        return array_map(static function (array $row) use ($channelLabels): array {
            $channel = (string) ($row['archive_channel'] ?? $row['channel'] ?? '');
            $saleDate = $row['legacy_sale_date'] ?? $row['sale_date'] ?? null;

            return [
                'order' => $row['legacy_order_label'] ?? $row['legacy_order_num'] ?? '',
                'channel' => $channelLabels[$channel] ?? $channel,
                'date' => $saleDate ? substr((string) $saleDate, 0, 10) : '',
                'customer' => $row['customer_name'] ?? '',
                'created_by' => $row['created_by'] ?? '',
                'total' => isset($row['order_total'])
                    ? number_format((float) $row['order_total'], 2, '.', ',')
                    : '',
                'materialized' => ! empty($row['materialized_sale_id']) ? 'Yes' : 'Archive only',
            ];
        }, $rows);
    }
}
