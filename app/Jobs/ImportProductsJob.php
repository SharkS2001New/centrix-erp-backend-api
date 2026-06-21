<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\Product;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ImportProductsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(BackgroundTaskService $tasks): void
    {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null) {
            return;
        }

        $tasks->markRunning($task);

        try {
            $user = User::query()->find($task->user_id);
            if ($user === null) {
                throw new \RuntimeException('User not found for product import task.');
            }

            $rows = $task->payload['rows'] ?? [];
            if (! is_array($rows) || count($rows) === 0) {
                throw new \RuntimeException('No product rows supplied for import.');
            }

            $created = 0;
            $failures = [];
            $total = count($rows);

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                try {
                    $body = $this->normalizeRow($row);
                    if (! $body['product_name'] || ! $body['subcategory_id'] || ! $body['unit_id']) {
                        throw new \InvalidArgumentException('Missing required fields.');
                    }

                    if (empty($body['product_code'])) {
                        $body['product_code'] = Product::generateNextProductCode((int) $user->organization_id);
                    }

                    $body['organization_id'] = (int) $user->organization_id;
                    $body['created_by'] = (int) $user->id;

                    Product::create($body);
                    $created++;
                } catch (\Throwable $e) {
                    $failures[] = [
                        'row' => $index + 1,
                        'code' => $row['product_code'] ?? $row['product_name'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                }

                if ($total > 0 && ($index + 1) % max(1, (int) floor($total / 20)) === 0) {
                    $tasks->updateProgress($task, (int) floor((($index + 1) / $total) * 100));
                }
            }

            $tasks->markCompleted($task, [
                'created' => $created,
                'failed' => count($failures),
                'failures' => array_slice($failures, 0, 50),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ImportProductsJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function normalizeRow(array $row): array
    {
        $body = [
            'product_code' => trim((string) ($row['product_code'] ?? '')),
            'product_name' => trim((string) ($row['product_name'] ?? '')),
            'subcategory_id' => (int) ($row['subcategory_id'] ?? 0),
            'unit_id' => (int) ($row['unit_id'] ?? 0),
            'unit_price' => (float) ($row['unit_price'] ?? 0),
        ];

        foreach ([
            'last_cost_price',
            'discount_type',
            'discount_percentage',
            'discount_value',
            'product_weight',
            'stock_in_shop',
            'stock_in_store',
            'reorder_point',
            'supplier_id',
            'vat_id',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== '' && $row[$key] !== null) {
                $body[$key] = $row[$key];
            }
        }

        $sell = strtolower(trim((string) ($row['sell_on_retail'] ?? '')));
        if (in_array($sell, ['true', '1', 'yes'], true)) {
            $body['sell_on_retail'] = true;
        } elseif (in_array($sell, ['false', '0', 'no'], true)) {
            $body['sell_on_retail'] = false;
        }

        return $body;
    }
}
