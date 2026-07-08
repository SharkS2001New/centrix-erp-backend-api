<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProcessesImportRowOutcomes;
use App\Jobs\Concerns\ResolvesImportRowsFromTask;
use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Product;
use App\Services\Inventory\OpeningStockService;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Support\Facades\DB;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportProductsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use ProcessesImportRowOutcomes;
    use ResolvesImportRowsFromTask;
    use RunsBackgroundTaskOnce;

    public int $timeout = 3600;

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

            $rows = $this->importRowsFromTask($task);
            if ($rows === []) {
                throw new \RuntimeException('No product rows supplied for import.');
            }

            $organizationId = $this->importOrganizationId($task, $user);
            $created = 0;
            $skipped = 0;
            $failures = [];
            $total = count($rows);
            $seenCodes = [];

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

                    $codeKey = strtolower(trim((string) $body['product_code']));
                    if ($codeKey !== '') {
                        if (isset($seenCodes[$codeKey])) {
                            $skipped++;

                            continue;
                        }

                        if (Product::query()
                            ->where('organization_id', $organizationId)
                            ->whereRaw('LOWER(TRIM(product_code)) = ?', [$codeKey])
                            ->exists()) {
                            $seenCodes[$codeKey] = true;
                            $skipped++;

                            continue;
                        }
                    }

                    $body['organization_id'] = $organizationId;
                    $body['created_by'] = (int) $user->id;

                    unset($body['stock_in_shop'], $body['stock_in_store']);
                    $openingShop = (float) ($row['stock_in_shop'] ?? 0);
                    $openingStore = (float) ($row['stock_in_store'] ?? 0);
                    $openingBranchId = $this->resolveOpeningBranchId($row, $user, $organizationId);

                    $product = Product::create($body);
                    if ($openingBranchId > 0 && ($openingShop > 0 || $openingStore > 0)) {
                        app(OpeningStockService::class)->applyOnProductCreate($user, $product->product_code, (int) $product->id, [
                            'branch_id' => $openingBranchId,
                            'shop_quantity' => $openingShop,
                            'store_quantity' => $openingStore,
                        ]);
                    }
                    if ($codeKey !== '') {
                        $seenCodes[$codeKey] = true;
                    }
                    $created++;
                } catch (\Throwable $e) {
                    if ($this->shouldSkipDuplicateImport($e)) {
                        $skipped++;

                        continue;
                    }

                    $failures[] = [
                        'row' => $index + 1,
                        'code' => $row['product_code'] ?? $row['product_name'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                }

                $this->reportImportLoopProgress($tasks, $task, $index, $total);
            }

            $this->completeImportTask($tasks, $task, $this->buildImportResult($created, $skipped, $failures));
        } catch (\Throwable $e) {
            $this->failImportTask($tasks, $task, $e, 'ImportProductsJob');
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

    /** @param  array<string, mixed>  $row */
    protected function resolveOpeningBranchId(array $row, User $user, int $organizationId): int
    {
        $fromRow = (int) ($row['branch_id'] ?? 0);
        if ($fromRow > 0) {
            return $fromRow;
        }

        if ($user->branch_id) {
            return (int) $user->branch_id;
        }

        return (int) (DB::table('branches')
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->value('id') ?? 0);
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
