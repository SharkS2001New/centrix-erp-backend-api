<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProcessesImportRowOutcomes;
use App\Jobs\Concerns\ResolvesImportRowsFromTask;
use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportSubCategoriesJob implements ShouldQueue
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
                throw new \RuntimeException('User not found for sub-category import task.');
            }

            $rows = $this->importRowsFromTask($task);
            if ($rows === []) {
                throw new \RuntimeException('No sub-category rows supplied for import.');
            }

            $organizationId = $this->importOrganizationId($task, $user);

            $categoryByName = Category::query()
                ->where('organization_id', $organizationId)
                ->get(['id', 'category_name'])
                ->mapWithKeys(fn (Category $category) => [strtolower(trim($category->category_name)) => (int) $category->id]);

            $created = 0;
            $skipped = 0;
            $failures = [];
            $total = count($rows);
            $seenKeys = [];

            foreach ($rows as $index => $row) {
                if (($index + 1) % 5 === 0) {
                    $tasks->assertNotCancelled($task);
                }

                if (! is_array($row)) {
                    continue;
                }

                try {
                    $name = trim((string) ($row['subcategory_name'] ?? ''));
                    if ($name === '') {
                        throw new \InvalidArgumentException('subcategory_name is required.');
                    }

                    $categoryId = (int) ($row['category_id'] ?? 0);
                    if ($categoryId <= 0) {
                        $categoryName = strtolower(trim((string) ($row['category_name'] ?? '')));
                        $categoryId = (int) ($categoryByName[$categoryName] ?? 0);
                    }
                    if ($categoryId <= 0) {
                        throw new \InvalidArgumentException('category_id or category_name is required.');
                    }

                    $categoryExists = Category::query()
                        ->where('id', $categoryId)
                        ->where('organization_id', $organizationId)
                        ->exists();
                    if (! $categoryExists) {
                        throw new \InvalidArgumentException('Category does not belong to this organization.');
                    }

                    $dupKey = $categoryId.'|'.strtolower($name);
                    if (isset($seenKeys[$dupKey])) {
                        $skipped++;

                        continue;
                    }

                    if (SubCategory::query()
                        ->where('organization_id', $organizationId)
                        ->where('category_id', $categoryId)
                        ->whereRaw('LOWER(TRIM(subcategory_name)) = ?', [strtolower($name)])
                        ->exists()) {
                        $seenKeys[$dupKey] = true;
                        $skipped++;

                        continue;
                    }

                    SubCategory::create([
                        'category_id' => $categoryId,
                        'subcategory_name' => $name,
                        'organization_id' => $organizationId,
                        'created_by' => (int) $user->id,
                    ]);
                    $seenKeys[$dupKey] = true;
                    $created++;
                } catch (\Throwable $e) {
                    if ($this->shouldSkipDuplicateImport($e)) {
                        $skipped++;

                        continue;
                    }

                    $failures[] = [
                        'row' => $index + 1,
                        'code' => $row['subcategory_name'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                }

                $this->reportImportLoopProgress($tasks, $task, $index, $total);
            }

            $this->completeImportTask($tasks, $task, $this->buildImportResult($created, $skipped, $failures));
        } catch (\Throwable $e) {
            $this->failImportTask($tasks, $task, $e, 'ImportSubCategoriesJob');
        }
    }
}
