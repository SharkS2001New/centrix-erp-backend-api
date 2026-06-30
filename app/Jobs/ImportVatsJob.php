<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProcessesImportRowOutcomes;
use App\Jobs\Concerns\ResolvesImportRowsFromTask;
use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Models\Vat;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportVatsJob implements ShouldQueue
{
    use Queueable;
    use ProcessesImportRowOutcomes;
    use ResolvesImportRowsFromTask;
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
                throw new \RuntimeException('User not found for VAT import task.');
            }

            $rows = $this->importRowsFromTask($task);
            if ($rows === []) {
                throw new \RuntimeException('No VAT rows supplied for import.');
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
                    $code = trim((string) ($row['vat_code'] ?? ''));
                    $name = trim((string) ($row['vat_name'] ?? ''));
                    if ($code === '' || $name === '') {
                        throw new \InvalidArgumentException('vat_code and vat_name are required.');
                    }

                    $codeKey = strtolower($code);
                    if (isset($seenCodes[$codeKey])) {
                        $skipped++;

                        continue;
                    }

                    if (Vat::query()
                        ->where('organization_id', $organizationId)
                        ->whereRaw('LOWER(TRIM(vat_code)) = ?', [$codeKey])
                        ->exists()) {
                        $seenCodes[$codeKey] = true;
                        $skipped++;

                        continue;
                    }

                    Vat::create([
                        'vat_code' => $code,
                        'vat_name' => $name,
                        'vat_percentage' => (float) ($row['vat_percentage'] ?? 0),
                        'is_active' => $this->parseBool($row['is_active'] ?? true, true),
                        'organization_id' => $organizationId,
                        'created_by' => (int) $user->id,
                    ]);
                    $seenCodes[$codeKey] = true;
                    $created++;
                } catch (\Throwable $e) {
                    if ($this->shouldSkipDuplicateImport($e)) {
                        $skipped++;

                        continue;
                    }

                    $failures[] = [
                        'row' => $index + 1,
                        'code' => $row['vat_code'] ?? $row['vat_name'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                }

                $this->reportImportLoopProgress($tasks, $task, $index, $total);
            }

            $this->completeImportTask($tasks, $task, $this->buildImportResult($created, $skipped, $failures));
        } catch (\Throwable $e) {
            $this->failImportTask($tasks, $task, $e, 'ImportVatsJob');
        }
    }

    protected function parseBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'y'], true);
    }
}
