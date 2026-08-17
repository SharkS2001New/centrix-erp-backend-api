<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProcessesImportRowOutcomes;
use App\Jobs\Concerns\ResolvesImportRowsFromTask;
use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportSuppliersJob implements ShouldQueue
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
        if ($this->shouldSkipBackgroundTask($task) || ! $tasks->markRunning($task)) {
            return;
        }

        try {
            $user = User::query()->find($task->user_id);
            if ($user === null) {
                throw new \RuntimeException('User not found for supplier import task.');
            }

            $rows = $this->importRowsFromTask($task);
            if ($rows === []) {
                throw new \RuntimeException('No supplier rows supplied for import.');
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
                    $providedCode = trim((string) ($row['supplier_code'] ?? ''));
                    $providedName = trim((string) ($row['supplier_name'] ?? ''));
                    $codeKey = $providedCode !== '' ? strtolower($providedCode) : '';
                    $nameKey = $providedName !== '' ? strtolower($providedName) : '';

                    if ($codeKey !== '' && isset($seenCodes[$codeKey])) {
                        $skipped++;

                        continue;
                    }
                    if ($nameKey !== '' && isset($seenNames[$nameKey])) {
                        $skipped++;

                        continue;
                    }

                    if ($this->supplierAlreadyExists($organizationId, $codeKey, $nameKey)) {
                        if ($codeKey !== '') {
                            $seenCodes[$codeKey] = true;
                        }
                        if ($nameKey !== '') {
                            $seenNames[$nameKey] = true;
                        }
                        $skipped++;

                        continue;
                    }

                    $body = $this->normalizeRow($row, $organizationId);
                    if ($body['supplier_name'] === '') {
                        throw new \InvalidArgumentException('Missing supplier name.');
                    }

                    $body['organization_id'] = $organizationId;
                    $body['created_by'] = (int) $user->id;

                    Supplier::create($body);
                    if ($codeKey !== '') {
                        $seenCodes[$codeKey] = true;
                    }
                    $seenNames[strtolower($body['supplier_name'])] = true;
                    $created++;
                } catch (\Throwable $e) {
                    if ($this->shouldSkipDuplicateImport($e)) {
                        $skipped++;

                        continue;
                    }

                    $failures[] = [
                        'row' => $index + 1,
                        'code' => $row['supplier_name'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                }

                $this->reportImportLoopProgress($tasks, $task, $index, $total);
            }

            $this->completeImportTask($tasks, $task, $this->buildImportResult($created, $skipped, $failures));
        } catch (\Throwable $e) {
            $this->failImportTask($tasks, $task, $e, 'ImportSuppliersJob');
        }
    }

    protected function supplierAlreadyExists(int $organizationId, string $codeKey, string $nameKey): bool
    {
        if ($codeKey !== '') {
            $byCode = Supplier::query()
                ->where('organization_id', $organizationId)
                ->whereRaw('LOWER(TRIM(supplier_code)) = ?', [$codeKey])
                ->exists();
            if ($byCode) {
                return true;
            }
        }

        if ($nameKey === '') {
            return false;
        }

        return Supplier::query()
            ->where('organization_id', $organizationId)
            ->whereRaw('LOWER(TRIM(supplier_name)) = ?', [$nameKey])
            ->exists();
    }

    /** @return array<string, mixed> */
    protected function normalizeRow(array $row, int $organizationId): array
    {
        $body = [
            'supplier_name' => trim((string) ($row['supplier_name'] ?? '')),
            'supplier_code' => trim((string) ($row['supplier_code'] ?? '')),
        ];

        if ($body['supplier_code'] === '') {
            $body['supplier_code'] = Supplier::generateNextSupplierCode($organizationId);
        }

        foreach ([
            'contact_person',
            'phone',
            'alternate_phone',
            'email',
            'town',
            'tax_pin',
            'address',
            'terms_of_payment',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== '' && $row[$key] !== null) {
                $body[$key] = trim((string) $row[$key]);
            }
        }

        $active = strtolower(trim((string) ($row['is_active'] ?? '')));
        if (in_array($active, ['false', '0', 'no'], true)) {
            $body['is_active'] = false;
        } elseif (in_array($active, ['true', '1', 'yes'], true)) {
            $body['is_active'] = true;
        } else {
            $body['is_active'] = true;
        }

        return $body;
    }
}
