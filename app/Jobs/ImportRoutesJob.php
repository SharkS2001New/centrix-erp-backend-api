<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProcessesImportRowOutcomes;
use App\Jobs\Concerns\ResolvesImportRowsFromTask;
use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Branch;
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
                throw new \RuntimeException('User not found for route import task.');
            }

            $organizationId = $this->importOrganizationId($task, $user);
            if ($organizationId <= 0) {
                throw new \RuntimeException('Route import requires an organization context.');
            }
            $defaultBranchId = $this->resolveRouteBranchId($user, $organizationId);

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

                    $branchId = (int) ($row['branch_id'] ?? 0);
                    if ($branchId > 0) {
                        $this->assertBranchInOrganization($organizationId, $branchId);
                    } else {
                        $branchId = $defaultBranchId;
                    }

                    $nameKey = strtolower($routeName).'|'.$branchId;
                    if (isset($seenNames[$nameKey])) {
                        $skipped++;

                        continue;
                    }

                    if (RouteModel::query()
                        ->where('organization_id', $organizationId)
                        ->where(function ($query) use ($branchId) {
                            if ($branchId > 0) {
                                $query->where('branch_id', $branchId);
                            } else {
                                $query->whereNull('branch_id');
                            }
                        })
                        ->whereRaw('LOWER(TRIM(route_name)) = ?', [strtolower($routeName)])
                        ->exists()) {
                        $seenNames[$nameKey] = true;
                        $skipped++;

                        continue;
                    }

                    // Org-wide unique on route_name still applies in DB for some tenants —
                    // also skip if same name already exists anywhere in the org.
                    if (RouteModel::query()
                        ->where('organization_id', $organizationId)
                        ->whereRaw('LOWER(TRIM(route_name)) = ?', [strtolower($routeName)])
                        ->exists()) {
                        $seenNames[$nameKey] = true;
                        $skipped++;

                        continue;
                    }

                    RouteModel::create([
                        'organization_id' => $organizationId,
                        'branch_id' => $branchId > 0 ? $branchId : null,
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

    protected function resolveRouteBranchId(User $user, int $organizationId): int
    {
        if (! empty($user->branch_id)) {
            $branchId = (int) $user->branch_id;
            if ($this->branchBelongsToOrganization($organizationId, $branchId)) {
                return $branchId;
            }
        }

        return $this->headOfficeBranchId($organizationId) ?? 0;
    }

    protected function assertBranchInOrganization(int $organizationId, int $branchId): void
    {
        if (! $this->branchBelongsToOrganization($organizationId, $branchId)) {
            throw new \InvalidArgumentException('branch_id does not belong to this organization.');
        }
    }

    protected function branchBelongsToOrganization(int $organizationId, int $branchId): bool
    {
        return Branch::query()
            ->where('organization_id', $organizationId)
            ->where('id', $branchId)
            ->exists();
    }

    protected function headOfficeBranchId(int $organizationId): ?int
    {
        $branch = Branch::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) {
                $query->where('branch_code', 'HQ')
                    ->orWhere('branch_name', 'like', '%Head Office%');
            })
            ->orderBy('id')
            ->first();

        if (! $branch) {
            $branch = Branch::query()
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->first();
        }

        return $branch ? (int) $branch->id : null;
    }
}
