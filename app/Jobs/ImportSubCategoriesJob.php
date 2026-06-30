<?php

namespace App\Jobs;

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

            $rows = $task->payload['rows'] ?? [];
            if (! is_array($rows) || count($rows) === 0) {
                throw new \RuntimeException('No sub-category rows supplied for import.');
            }

            $categoryByName = Category::query()
                ->get(['id', 'category_name'])
                ->mapWithKeys(fn (Category $category) => [strtolower(trim($category->category_name)) => (int) $category->id]);

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

                    SubCategory::create([
                        'category_id' => $categoryId,
                        'subcategory_name' => $name,
                        'created_by' => (int) $user->id,
                    ]);
                    $created++;
                } catch (\Throwable $e) {
                    $failures[] = [
                        'row' => $index + 1,
                        'code' => $row['subcategory_name'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                }

                if ($total > 0 && ($index + 1) % max(1, (int) floor($total / 20)) === 0) {
                    $this->reportProgress($tasks, $task, (int) floor((($index + 1) / $total) * 100));
                }
            }

            $tasks->assertNotCancelled($task);
            $tasks->markCompleted($task, [
                'created' => $created,
                'failed' => count($failures),
                'failures' => array_slice($failures, 0, 50),
            ]);
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'ImportSubCategoriesJob');
        }
    }
}
