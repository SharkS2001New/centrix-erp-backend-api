<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportProductsJob implements ShouldQueue
{
    use Queueable;
    use RunsBackgroundTaskOnce;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(BackgroundTaskService $tasks): void
    {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($this->shouldSkipBackgroundTask($task)) {
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

            $organizationId = $this->importOrganizationId($task, $user);
            $created = 0;
            $failures = [];
            $total = count($rows);

            foreach ($rows as $index => $row) {
                if (($index + 1) % 5 === 0) {
                    $tasks->assertNotCancelled($task);
                }

                if (! is_array($row)) {
                    continue;
                }

                try {
                    $body = $this->normalizeRow($row, $organizationId);
                    if (! $body['product_name'] || ! $body['subcategory_id'] || ! $body['unit_id']) {
                        throw new \InvalidArgumentException(
                            'Missing required fields: product_name, subcategory (id or name), and unit (id or measure_name).',
                        );
                    }

                    if (empty($body['product_code'])) {
                        $body['product_code'] = Product::generateNextProductCode($organizationId);
                    }

                    $body['organization_id'] = $organizationId;
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
                    $this->reportProgress(
                        $tasks,
                        $task,
                        (int) floor((($index + 1) / $total) * 100),
                    );
                }
            }

            $tasks->assertNotCancelled($task);
            $tasks->markCompleted($task, [
                'created' => $created,
                'failed' => count($failures),
                'failures' => array_slice($failures, 0, 50),
            ]);
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'ImportProductsJob');
        }
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function normalizeRow(array $row, int $organizationId): array
    {
        $body = [
            'product_code' => trim((string) ($row['product_code'] ?? '')),
            'product_name' => trim((string) ($row['product_name'] ?? '')),
            'subcategory_id' => (int) ($row['subcategory_id'] ?? 0),
            'unit_id' => (int) ($row['unit_id'] ?? 0),
            'unit_price' => (float) ($row['unit_price'] ?? 0),
        ];

        $this->resolveForeignKeys($body, $row, $organizationId);

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

    /** @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $row
     */
    protected function resolveForeignKeys(array &$body, array $row, int $organizationId): void
    {
        if ((int) ($body['subcategory_id'] ?? 0) <= 0) {
            $subcategoryName = trim((string) ($row['subcategory_name'] ?? ''));
            if ($subcategoryName !== '') {
                $query = SubCategory::query()
                    ->where('organization_id', $organizationId)
                    ->where('subcategory_name', $subcategoryName);

                $categoryName = trim((string) ($row['category_name'] ?? ''));
                if ($categoryName !== '') {
                    $query->whereHas('category', fn ($q) => $q
                        ->where('organization_id', $organizationId)
                        ->where('category_name', $categoryName));
                }

                $subcategory = $query->first();
                if ($subcategory !== null) {
                    $body['subcategory_id'] = (int) $subcategory->id;
                }
            }
        }

        if ((int) ($body['unit_id'] ?? 0) <= 0) {
            $measureName = trim((string) ($row['measure_name'] ?? ''));
            if ($measureName !== '') {
                $uom = Uom::query()
                    ->where('organization_id', $organizationId)
                    ->where(function ($q) use ($measureName) {
                        $q->where('measure_name', $measureName)
                            ->orWhere('full_name', $measureName);
                    })
                    ->first();
                if ($uom !== null) {
                    $body['unit_id'] = (int) $uom->id;
                }
            }
        }

        if (empty($body['vat_id'])) {
            $vatCode = trim((string) ($row['vat_code'] ?? ''));
            if ($vatCode !== '') {
                $vat = Vat::query()
                    ->where('organization_id', $organizationId)
                    ->where('vat_code', $vatCode)
                    ->first();
                if ($vat !== null) {
                    $body['vat_id'] = (int) $vat->id;
                }
            }
        }

        if (empty($body['supplier_id'])) {
            $supplierName = trim((string) ($row['supplier_name'] ?? ''));
            if ($supplierName !== '') {
                $supplier = Supplier::query()
                    ->where('organization_id', $organizationId)
                    ->where('supplier_name', $supplierName)
                    ->first();
                if ($supplier !== null) {
                    $body['supplier_id'] = (int) $supplier->id;
                }
            }
        }
    }
}
