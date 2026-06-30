<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Uom;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportUomsJob implements ShouldQueue
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
                throw new \RuntimeException('User not found for UOM import task.');
            }

            $rows = $task->payload['rows'] ?? [];
            if (! is_array($rows) || count($rows) === 0) {
                throw new \RuntimeException('No UOM rows supplied for import.');
            }

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
                    $body = $this->normalizeRow($row);
                    if (! $body['measure_name']) {
                        throw new \InvalidArgumentException('measure_name is required.');
                    }

                    $body['created_by'] = (int) $user->id;
                    Uom::create($body);
                    $created++;
                } catch (\Throwable $e) {
                    $failures[] = [
                        'row' => $index + 1,
                        'code' => $row['measure_name'] ?? $row['full_name'] ?? null,
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
            $this->failBackgroundTask($tasks, $task, $e, 'ImportUomsJob');
        }
    }

    /** @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalizeRow(array $row): array
    {
        $measureName = trim((string) ($row['measure_name'] ?? ''));
        $fullName = trim((string) ($row['full_name'] ?? ''));
        $middleLabel = trim((string) ($row['middle_packaging_label'] ?? ''));
        $middleFactor = trim((string) ($row['middle_factor'] ?? ''));

        return [
            'measure_name' => $measureName,
            'full_name' => $fullName !== '' ? $fullName : $measureName,
            'small_packaging_label' => trim((string) ($row['small_packaging_label'] ?? 'piece')) ?: 'piece',
            'middle_packaging_label' => $middleLabel !== '' ? $middleLabel : null,
            'middle_factor' => $middleFactor !== '' ? (float) $middleFactor : null,
            'conversion_factor' => (float) ($row['conversion_factor'] ?? 1) ?: 1,
            'uom_type' => trim((string) ($row['uom_type'] ?? 'piece')) ?: 'piece',
            'is_active' => $this->parseBool($row['is_active'] ?? true, true),
        ];
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
