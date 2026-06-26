<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Background\InternalApiPaginator;
use App\Services\Background\ProductCatalogExportFetcher;
use App\Services\Background\ReportExportSearchParams;
use App\Services\Background\ReportExportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class GenerateReportExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RunsBackgroundTaskOnce;

    public int $timeout = 3600;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(
        BackgroundTaskService $tasks,
        InternalApiPaginator $paginator,
        ProductCatalogExportFetcher $productCatalog,
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
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $footerRow = $payload['footer_row'] ?? null;
            $filename = (string) ($payload['filename'] ?? 'report');
            $source = (string) ($payload['source'] ?? 'api');

            if (! is_array($columns) || count($columns) === 0) {
                throw new InvalidArgumentException('Export columns are required.');
            }

            $truncated = false;
            $onProgress = function (int $progress, string $message) use ($tasks, $task): void {
                $this->reportProgress($tasks, $task, $progress, $message);
            };

            $streamSource = $this->buildStreamSource(
                $payload,
                $source,
                $paginator,
                $productCatalog,
                $user,
                $task,
                $tasks,
                $onProgress,
                $truncated,
            );

            $tasks->assertNotCancelled($task);
            $tasks->updateProgress($task, 88, 'Generating file…');

            $file = $exporter->generateStreaming(
                $format,
                $filename,
                $meta,
                $columns,
                is_array($footerRow) ? $footerRow : null,
                (int) $user->organization_id,
                $task->id,
                $streamSource,
                function (int $progress, string $message) use ($tasks, $task): void {
                    $this->reportProgress($tasks, $task, $progress, $message);
                },
            );

            $tasks->assertNotCancelled($task);
            $tasks->updateProgress($task, 98, 'Almost done…');
            $tasks->markCompleted($task, array_merge($file, [
                'truncated' => $truncated || (bool) ($file['truncated'] ?? false) || (bool) ($file['pdf_truncated'] ?? false),
            ]));
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'GenerateReportExportJob');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return callable(callable(list<array<string, mixed>>): void): void
     */
    protected function buildStreamSource(
        array $payload,
        string $source,
        InternalApiPaginator $paginator,
        ProductCatalogExportFetcher $productCatalog,
        User $user,
        BackgroundTask $task,
        BackgroundTaskService $tasks,
        callable $onProgress,
        bool &$truncated,
    ): callable {
        return function (callable $onBatch) use (
            $payload,
            $source,
            $paginator,
            $productCatalog,
            $user,
            $task,
            $tasks,
            $onProgress,
            &$truncated,
        ): void {
            if ($source === 'inline_rows') {
                $rows = $payload['rows'] ?? [];
                if (is_array($rows) && $rows !== []) {
                    $onBatch(array_values(array_filter($rows, 'is_array')));
                }

                return;
            }

            if ($source === 'legacy_archive_sales') {
                $searchParams = ReportExportSearchParams::sanitize($payload['search_params'] ?? []);
                $onProgress(8, 'Fetching legacy archive sales…');
                $result = $paginator->eachPage(
                    '/reports/legacy-archive/sales',
                    $searchParams,
                    $user,
                    function (array $batch) use ($onBatch): void {
                        $onBatch($this->mapLegacyArchiveSalesRows($batch));
                    },
                    InternalApiPaginator::API_VALIDATED_MAX_PER_PAGE,
                    null,
                    $onProgress,
                    $task,
                );
                $truncated = $truncated || $result['truncated'];

                return;
            }

            if ($source === 'product_catalog') {
                $searchParams = ReportExportSearchParams::sanitize($payload['search_params'] ?? []);
                $onProgress(8, 'Fetching products…');
                $result = $productCatalog->eachPage(
                    $searchParams,
                    $user,
                    function (array $batch) use ($onBatch): void {
                        $onBatch($this->mapProductCatalogRows($batch));
                    },
                    null,
                    null,
                    $onProgress,
                    $task,
                );
                $truncated = $truncated || $result['truncated'];

                return;
            }

            $path = (string) ($payload['path'] ?? '');
            if ($path === '') {
                throw new InvalidArgumentException('API path is required for report export.');
            }

            $searchParams = ReportExportSearchParams::sanitize($payload['search_params'] ?? []);
            $onProgress(8, 'Fetching report data…');
            $result = $paginator->eachPage(
                $path,
                $searchParams,
                $user,
                $onBatch,
                null,
                null,
                $onProgress,
                $task,
            );
            $truncated = $truncated || $result['truncated'];

            if (! empty($payload['legacy_merge']['enabled'])) {
                $tasks->updateProgress($task, 72, 'Loading legacy archive data…');
                $paginator->eachLegacyArchiveMerge(
                    $path,
                    $searchParams,
                    $user,
                    $onBatch,
                    $onProgress,
                    $task,
                );
            }
        };
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
