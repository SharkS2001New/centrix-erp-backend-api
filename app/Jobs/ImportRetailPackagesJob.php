<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProcessesImportRowOutcomes;
use App\Jobs\Concerns\ResolvesImportRowsFromTask;
use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportRetailPackagesJob implements ShouldBeUnique, ShouldQueue
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
                throw new \RuntimeException('User not found for retail package import task.');
            }

            $rows = $this->importRowsFromTask($task);
            if ($rows === []) {
                throw new \RuntimeException('No retail package rows supplied for import.');
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
                    $body = $this->normalizeRow($row);
                    $productCode = $body['product_code'];
                    if ($productCode === '') {
                        throw new \InvalidArgumentException('product_code is required.');
                    }

                    $codeKey = strtolower($productCode);
                    if (isset($seenCodes[$codeKey])) {
                        $skipped++;

                        continue;
                    }
                    $seenCodes[$codeKey] = true;

                    $product = Product::query()
                        ->where('organization_id', $organizationId)
                        ->whereRaw('LOWER(TRIM(product_code)) = ?', [$codeKey])
                        ->first();

                    if ($product === null) {
                        throw new \InvalidArgumentException("Product not found: {$productCode}");
                    }

                    $body['product_code'] = (string) $product->product_code;

                    RetailPackageSetting::query()->updateOrCreate(
                        ['product_code' => $body['product_code']],
                        $body,
                    );

                    $created++;
                } catch (\Throwable $e) {
                    if ($this->shouldSkipDuplicateImport($e)) {
                        $skipped++;

                        continue;
                    }

                    $failures[] = [
                        'row' => $index + 1,
                        'code' => $row['product_code'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                }

                $this->reportImportLoopProgress($tasks, $task, $index, $total);
            }

            $this->completeImportTask($tasks, $task, $this->buildImportResult($created, $skipped, $failures));
        } catch (\Throwable $e) {
            $this->failImportTask($tasks, $task, $e, 'ImportRetailPackagesJob');
        }
    }

    /** @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalizeRow(array $row): array
    {
        $productCode = trim((string) ($row['product_code'] ?? ''));

        $body = [
            'product_code' => $productCode,
            'max_qty_measure' => $this->nullableFloat($row['max_qty_measure'] ?? null),
            'markup_price' => (float) ($row['markup_price'] ?? 0),
            'min_uom_measure' => $this->nullableString($row['min_uom_measure'] ?? null),
            'max_uom_measure' => $this->nullableString($row['max_uom_measure'] ?? null),
            'wholesale_qty_measure' => (float) ($row['wholesale_qty_measure'] ?? 0),
            'wholesale_markup_price' => (float) ($row['wholesale_markup_price'] ?? 0),
        ];

        $tiers = $this->parsePricingTiers($row['pricing_tiers'] ?? null);
        if ($tiers !== null) {
            $body['pricing_tiers'] = $tiers;
        }

        return $body;
    }

    /** @return array<int, array<string, mixed>>|null */
    protected function parsePricingTiers(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            $decoded = json_decode($trimmed, true);
            if (! is_array($decoded)) {
                throw new \InvalidArgumentException('pricing_tiers must be valid JSON.');
            }
            $value = $decoded;
        }

        if (! is_array($value)) {
            throw new \InvalidArgumentException('pricing_tiers must be a JSON array.');
        }

        $tiers = [];
        foreach ($value as $tier) {
            if (! is_array($tier)) {
                continue;
            }
            if (($tier['min_qty'] ?? '') === '' && ($tier['min_qty'] ?? null) === null) {
                continue;
            }
            $tiers[] = [
                'min_qty' => (float) $tier['min_qty'],
                'max_qty' => ($tier['max_qty'] ?? '') === '' || ($tier['max_qty'] ?? null) === null
                    ? null
                    : (float) $tier['max_qty'],
                'measure_level' => trim((string) ($tier['measure_level'] ?? 'small')) ?: 'small',
                'price_mode' => strtolower(trim((string) ($tier['price_mode'] ?? 'retail'))) === 'wholesale'
                    ? 'wholesale'
                    : 'retail',
                'markup_price' => (float) ($tier['markup_price'] ?? 0),
            ];
        }

        return $tiers === [] ? null : $tiers;
    }

    protected function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    protected function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
