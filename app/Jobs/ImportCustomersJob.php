<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Customer;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Customers\CustomerUniquenessValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ImportCustomersJob implements ShouldQueue
{
    use Queueable;
    use RunsBackgroundTaskOnce;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(
        BackgroundTaskService $tasks,
        CustomerUniquenessValidator $customerUniqueness,
    ): void {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($this->shouldSkipBackgroundTask($task)) {
            return;
        }

        $tasks->markRunning($task);

        try {
            $user = User::query()->find($task->user_id);
            if ($user === null) {
                throw new \RuntimeException('User not found for customer import task.');
            }

            $rows = $task->payload['rows'] ?? [];
            if (! is_array($rows) || count($rows) === 0) {
                throw new \RuntimeException('No customer rows supplied for import.');
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
                    $body = $this->normalizeRow($row);
                    if ($body['customer_name'] === '') {
                        throw new \InvalidArgumentException('Missing customer name.');
                    }

                    $customerUniqueness->assertUnique(
                        $organizationId,
                        $body['phone_number'] ?? null,
                        $body['additional_phone'] ?? null,
                        $body['kra_pin'] ?? null,
                    );

                    DB::transaction(function () use ($body, $organizationId, $user): void {
                        if (empty($body['customer_num'])) {
                            $max = Customer::query()
                                ->where('organization_id', $organizationId)
                                ->lockForUpdate()
                                ->max('customer_num');
                            $body['customer_num'] = ((int) $max) + 1;
                        }

                        $body['organization_id'] = $organizationId;
                        $body['created_by'] = (int) $user->id;

                        Customer::create($body);
                    });

                    $created++;
                } catch (\Throwable $e) {
                    $failures[] = [
                        'row' => $index + 1,
                        'code' => $row['customer_name'] ?? null,
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
            $this->failBackgroundTask($tasks, $task, $e, 'ImportCustomersJob');
        }
    }

    /** @return array<string, mixed> */
    protected function normalizeRow(array $row): array
    {
        $body = [
            'customer_name' => trim((string) ($row['customer_name'] ?? '')),
            'customer_type' => strtolower(trim((string) ($row['customer_type'] ?? 'debtor'))) ?: 'debtor',
        ];

        if (! in_array($body['customer_type'], ['debtor', 'route'], true)) {
            $body['customer_type'] = 'debtor';
        }

        foreach ([
            'phone_number',
            'additional_phone',
            'email',
            'town',
            'kra_pin',
            'terms_of_payment',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== '' && $row[$key] !== null) {
                $body[$key] = trim((string) $row[$key]);
            }
        }

        foreach (['branch_id', 'route_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== '' && $row[$key] !== null) {
                $body[$key] = (int) $row[$key];
            }
        }

        if ($body['customer_type'] !== 'route') {
            $body['route_id'] = null;
        }

        if (array_key_exists('credit_limit', $row) && $row['credit_limit'] !== '' && $row['credit_limit'] !== null) {
            $body['credit_limit'] = (float) $row['credit_limit'];
        }

        $lat = $row['latitude'] ?? null;
        $lng = $row['longitude'] ?? null;
        $latSet = $lat !== null && $lat !== '';
        $lngSet = $lng !== null && $lng !== '';
        if ($latSet && $lngSet) {
            $body['latitude'] = round((float) $lat, 7);
            $body['longitude'] = round((float) $lng, 7);
        }

        if (! empty($row['customer_num'])) {
            $body['customer_num'] = (int) $row['customer_num'];
        }

        return $body;
    }
}
