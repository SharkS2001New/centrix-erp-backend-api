<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProcessesImportRowOutcomes;
use App\Jobs\Concerns\ResolvesImportRowsFromTask;
use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Branch;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use App\Services\Auth\UserAccessService;
use App\Services\Background\BackgroundTaskService;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Services\Inventory\OpeningStockService;
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

    public function handle(
        BackgroundTaskService $tasks,
        ProductCatalogScopeService $catalogScope,
        UserAccessService $access,
    ): void {
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
            $seenNames = [];

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

                    $body = $this->applyCatalogScope($body, $row, $user, $organizationId, $catalogScope, $access);
                    $catalogBranchId = isset($body['branch_id']) ? (int) $body['branch_id'] : null;
                    if ($catalogBranchId !== null && $catalogBranchId <= 0) {
                        $catalogBranchId = null;
                        $body['branch_id'] = null;
                    }

                    $codeKey = strtolower(trim((string) ($body['product_code'] ?? '')));
                    $nameKey = strtolower(trim((string) $body['product_name']));
                    $nameScopeKey = $nameKey.'|'.($catalogBranchId ?? 'org');

                    if ($codeKey !== '' && isset($seenCodes[$codeKey])) {
                        $skipped++;

                        continue;
                    }
                    if (isset($seenNames[$nameScopeKey])) {
                        $skipped++;

                        continue;
                    }

                    if ($this->productAlreadyExists($organizationId, $codeKey, $nameKey, $catalogBranchId)) {
                        if ($codeKey !== '') {
                            $seenCodes[$codeKey] = true;
                        }
                        $seenNames[$nameScopeKey] = true;
                        $skipped++;

                        continue;
                    }

                    if ($codeKey === '') {
                        $body['product_code'] = Product::generateNextProductCode($organizationId);
                        $codeKey = strtolower(trim((string) $body['product_code']));
                    }

                    $body['organization_id'] = $organizationId;
                    $body['created_by'] = (int) $user->id;

                    unset($body['stock_in_shop'], $body['stock_in_store']);
                    $openingShop = (float) ($row['stock_in_shop'] ?? 0);
                    $openingStore = (float) ($row['stock_in_store'] ?? 0);
                    $openingBranchId = $this->resolveOpeningBranchId($row, $user, $organizationId, $access, $catalogBranchId);

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
                    $seenNames[$nameScopeKey] = true;
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

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function applyCatalogScope(
        array $body,
        array $row,
        User $user,
        int $organizationId,
        ProductCatalogScopeService $catalogScope,
        UserAccessService $access,
    ): array {
        $catalogScopeValue = strtolower(trim((string) ($row['catalog_scope'] ?? '')));
        $rowBranchId = (int) ($row['branch_id'] ?? 0);
        $limitedBranch = $access->branchId($user);

        if ($limitedBranch !== null) {
            $catalogScopeValue = 'branch';
            $rowBranchId = $limitedBranch;
        } elseif ($rowBranchId > 0 && $catalogScopeValue === '') {
            $catalogScopeValue = 'branch';
        } elseif ($catalogScopeValue === '') {
            $catalogScopeValue = 'organization';
        }

        $scoped = $catalogScope->normalizeWriteData($user, [
            'organization_id' => $organizationId,
            'catalog_scope' => $catalogScopeValue,
            'branch_id' => $rowBranchId > 0 ? $rowBranchId : null,
        ]);

        $body['branch_id'] = $scoped['branch_id'] ?? null;

        return $body;
    }

    protected function productAlreadyExists(
        int $organizationId,
        string $codeKey,
        string $nameKey,
        ?int $catalogBranchId,
    ): bool {
        if ($codeKey !== '') {
            $byCode = Product::query()
                ->where('organization_id', $organizationId)
                ->whereRaw('LOWER(TRIM(product_code)) = ?', [$codeKey])
                ->exists();
            if ($byCode) {
                return true;
            }
        }

        if ($nameKey === '') {
            return false;
        }

        $byName = Product::query()
            ->where('organization_id', $organizationId)
            ->whereRaw('LOWER(TRIM(product_name)) = ?', [$nameKey]);

        if ($catalogBranchId !== null) {
            // Branch catalog: skip if org-wide or same-branch product already uses this name.
            $byName->where(function ($query) use ($catalogBranchId) {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $catalogBranchId);
            });
        }

        return $byName->exists();
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

        foreach (['sell_on_bar', 'sell_on_hotel'] as $channelKey) {
            $channel = strtolower(trim((string) ($row[$channelKey] ?? '')));
            if (in_array($channel, ['true', '1', 'yes'], true)) {
                $body[$channelKey] = true;
            } elseif (in_array($channel, ['false', '0', 'no'], true)) {
                $body[$channelKey] = false;
            }
        }

        return $body;
    }

    /** @param  array<string, mixed>  $row */
    protected function resolveOpeningBranchId(
        array $row,
        User $user,
        int $organizationId,
        UserAccessService $access,
        ?int $catalogBranchId,
    ): int {
        $limitedBranch = $access->branchId($user);
        if ($limitedBranch !== null) {
            return $limitedBranch;
        }

        $fromRow = (int) ($row['opening_branch_id'] ?? $row['branch_id'] ?? 0);
        if ($fromRow > 0) {
            $this->assertBranchInOrganization($organizationId, $fromRow);

            return $fromRow;
        }

        if ($catalogBranchId !== null && $catalogBranchId > 0) {
            return $catalogBranchId;
        }

        if ($user->branch_id) {
            $branchId = (int) $user->branch_id;
            $this->assertBranchInOrganization($organizationId, $branchId);

            return $branchId;
        }

        return (int) (Branch::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN branch_code = 'HQ' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->value('id') ?? 0);
    }

    protected function assertBranchInOrganization(int $organizationId, int $branchId): void
    {
        $exists = Branch::query()
            ->where('organization_id', $organizationId)
            ->where('id', $branchId)
            ->exists();

        if (! $exists) {
            throw new \InvalidArgumentException('branch_id does not belong to this organization.');
        }
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

        if (empty($body['vat_id'])) {
            $defaultVatId = Vat::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->orderBy('id')
                ->value('id');
            if ($defaultVatId) {
                $body['vat_id'] = (int) $defaultVatId;
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
