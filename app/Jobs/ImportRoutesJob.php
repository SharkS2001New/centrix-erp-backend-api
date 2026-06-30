<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProcessesImportRowOutcomes;
use App\Jobs\Concerns\ResolvesImportRowsFromTask;
use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\RouteModel;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportRoutesJob implements ShouldQueue
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
                throw new \RuntimeException('User not found for route import task.');
            }

            $organizationId = $this->importOrganizationId($task, $user);
            if ($organizationId <= 0) {
                throw new \RuntimeException('Route import requires an organization context.');
            }

            $rows = $this->importRowsFromTask($task);
            if ($rows === []) {
                throw new \RuntimeException('No route rows supplied for import.');
            }

            $created = 0;
            $skipped = 0;
            $failures = [];
            $total = count($rows);
            $seenNames = [];

            foreach ($rows as $index => $row) {
                if (($index + 1) % 5 === 0) {
                    $tasks->assertNotCancelled($task);
                }

                if (! is_array($row)) {
                    continue;
                }

                try {
                    $routeName = trim((string) ($row['route_name'] ?? ''));
                    if ($routeName === '') {
                        throw new \InvalidArgumentException('route_name is required.');
                    }

                    $nameKey = strtolower($routeName);
                    if (isset($seenNames[$nameKey])) {
                        $skipped++;

                        continue;
                    }

                    if (RouteModel::query()
                        ->where('organization_id', $organizationId)
                        ->whereRaw('LOWER(TRIM(route_name)) = ?', [$nameKey])
                        ->exists()) {
                        $seenNames[$nameKey] = true;
                        $skipped++;

                        continue;
                    }

                    RouteModel::create([
                        'organization_id' => $organizationId,
                        'route_name' => $routeName,
                        'direction' => trim((string) ($row['direction'] ?? '')) ?: null,
                        'route_markup_price' => (int) ($row['route_markup_price'] ?? 0),
                        'is_active' => $this->parseBool($row['is_active'] ?? true, true),
                    ]);
                    $seenNames[$nameKey] = true;
                    $created++;
                } catch (\Throwable $e) {
                    if ($this->shouldSkipDuplicateImport($e)) {
                        $skipped++;

                        continue;
                    }

                    $failures[] = [
                        'row' => $index + 1,
                        'code' => $row['route_name'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                }

                $this->reportImportLoopProgress($tasks, $task, $index, $total);
            }

            $this->completeImportTask($tasks, $task, $this->buildImportResult($created, $skipped, $failures));
        } catch (\Throwable $e) {
            $this->failImportTask($tasks, $task, $e, 'ImportRoutesJob');
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
