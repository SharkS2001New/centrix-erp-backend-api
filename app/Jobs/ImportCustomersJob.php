<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\RouteModel;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Background\BackgroundTaskService;
use App\Services\Customers\CustomerNumberAllocator;
use App\Services\Customers\CustomerUniquenessValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        UserAccessService $access,
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

            $organizationId = $this->importOrganizationId($task, $user);
            if ($organizationId <= 0) {
                throw new \RuntimeException('Customer import requires an organization context.');
            }

            $defaultBranchId = $this->resolveImportBranchId($user, $organizationId, $access);
            $validRouteIds = RouteModel::query()
                ->where('organization_id', $organizationId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $validRouteSet = array_fill_keys($validRouteIds, true);

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
                    $body = $this->normalizeRow(
                        $row,
                        $user,
                        $organizationId,
                        $defaultBranchId,
                        $validRouteSet,
                        $access,
                    );
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
                            $body['customer_num'] = app(CustomerNumberAllocator::class)
                                ->nextForOrganization($organizationId);
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
                        'message' => $this->formatImportFailureMessage($e),
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

    protected function resolveImportBranchId(User $user, int $organizationId, UserAccessService $access): int
    {
        $limitedBranch = $access->branchId($user);
        if ($limitedBranch !== null) {
            return $limitedBranch;
        }

        if (! empty($user->branch_id)) {
            return (int) $user->branch_id;
        }

        $branch = Branch::query()
            ->where('organization_id', $organizationId)
            ->orderByRaw("CASE WHEN branch_code = 'HQ' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if ($branch === null) {
            throw new \RuntimeException('Organization has no branch. Create a branch before importing customers.');
        }

        return (int) $branch->id;
    }

    /**
     * @param  array<string, true>  $validRouteSet
     * @return array<string, mixed>
     */
    protected function normalizeRow(
        array $row,
        User $user,
        int $organizationId,
        int $defaultBranchId,
        array $validRouteSet,
        UserAccessService $access,
    ): array {
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

        if (array_key_exists('route_id', $row) && $row['route_id'] !== '' && $row['route_id'] !== null) {
            $body['route_id'] = (int) $row['route_id'];
        }

        $routeName = trim((string) ($row['route_name'] ?? ''));
        if ($routeName !== '' && empty($body['route_id'])) {
            $route = RouteModel::query()
                ->where('organization_id', $organizationId)
                ->where('route_name', $routeName)
                ->first();
            if ($route !== null) {
                $body['route_id'] = (int) $route->id;
            }
        }

        if ($body['customer_type'] !== 'route') {
            $body['route_id'] = null;
        } elseif (empty($body['route_id'])) {
            if ($routeName !== '') {
                throw new \InvalidArgumentException(
                    'Route "'.$routeName.'" was not found. Import routes before importing route customers.',
                );
            }
            throw new \InvalidArgumentException('Route is required for route customers (route_name or route_id).');
        } elseif (! isset($validRouteSet[(int) $body['route_id']])) {
            throw new \InvalidArgumentException(
                'Route ID '.(int) $body['route_id'].' does not exist. Create routes before importing route customers.',
            );
        }

        $requestedBranchId = null;
        if (array_key_exists('branch_id', $row) && $row['branch_id'] !== '' && $row['branch_id'] !== null) {
            $requestedBranchId = (int) $row['branch_id'];
        }

        $limitedBranch = $access->branchId($user);
        if ($limitedBranch !== null) {
            if ($requestedBranchId !== null && $requestedBranchId !== $limitedBranch) {
                throw new \InvalidArgumentException('You can only import customers into your assigned branch.');
            }
            $body['branch_id'] = $limitedBranch;
        } else {
            $body['branch_id'] = $requestedBranchId ?? $defaultBranchId;
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

    protected function formatImportFailureMessage(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $messages = $e->validator?->errors()?->all() ?? [];

            return $messages !== [] ? implode(' ', $messages) : $e->getMessage();
        }

        return $e->getMessage();
    }
}
