<?php

namespace App\Jobs;

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
                throw new \RuntimeException('User not found for supplier import task.');
            }

            $rows = $task->payload['rows'] ?? [];
            if (! is_array($rows) || count($rows) === 0) {
                throw new \RuntimeException('No supplier rows supplied for import.');
            }

            $organizationId = (int) $user->organization_id;
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
                    $body = $this->normalizeRow($row, $organizationId);
                    if ($body['supplier_name'] === '') {
                        throw new \InvalidArgumentException('Missing supplier name.');
                    }

                    $body['organization_id'] = $organizationId;
                    $body['created_by'] = (int) $user->id;

                    Supplier::create($body);
                    $created++;
                } catch (\Throwable $e) {
                    $failures[] = [
                        'row' => $index + 1,
                        'code' => $row['supplier_name'] ?? null,
                        'message' => $e->getMessage(),
                    ];
                }

                if ($total > 0 && ($index + 1) % max(1, (int) floor($total / 20)) === 0) {
                    $this->reportProgress(
                        $tasks,
                        $task,
                        (int) floor((($index + 1) / $total) * 100),
                    );
                }
            }

            $tasks->assertNotCancelled($task);
            $tasks->markCompleted($task, [
                'created' => $created,
                'failed' => count($failures),
                'failures' => array_slice($failures, 0, 50),
            ]);
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'ImportSuppliersJob');
        }
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
