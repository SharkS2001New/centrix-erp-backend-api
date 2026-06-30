<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProcessesImportRowOutcomes;
use App\Jobs\Concerns\ResolvesImportRowsFromTask;
use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Category;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportCategoriesJob implements ShouldBeUnique, ShouldQueue
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
                throw new \RuntimeException('User not found for category import task.');
            }

            $rows = $this->importRowsFromTask($task);
            if ($rows === []) {
                throw new \RuntimeException('No category rows supplied for import.');
            }

            $organizationId = $this->importOrganizationId($task, $user);

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
                    $name = trim((string) ($row['category_name'] ?? ''));
                    if ($name === '') {
                        throw new \InvalidArgumentException('category_name is required.');
                    }

                    $nameKey = strtolower($name);
                    if (isset($seenNames[$nameKey])) {
                        $skipped++;

                        continue;
                    }

                    if (Category::query()
                        ->where('organization_id', $organizationId)
                        ->whereRaw('LOWER(TRIM(category_name)) = ?', [$nameKey])
                        ->exists()) {
                        $seenNames[$nameKey] = true;
                        $skipped++;

                        continue;
                    }

                    Category::create([
                        'category_name' => $name,
                        'organization_id' => $organizationId,
                        'created_by' => (int) $user->id,
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
                        'code' => $row['category_name'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                }

                $this->reportImportLoopProgress($tasks, $task, $index, $total);
            }

            $this->completeImportTask($tasks, $task, $this->buildImportResult($created, $skipped, $failures));
        } catch (\Throwable $e) {
            $this->failImportTask($tasks, $task, $e, 'ImportCategoriesJob');
        }
    }
}
