<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProcessesImportRowOutcomes;
use App\Jobs\Concerns\ResolvesImportRowsFromTask;
use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Employee;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Hr\HrPayrollSettingsResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ImportEmployeesJob implements ShouldQueue
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
                throw new \RuntimeException('User not found for employee import task.');
            }

            $rows = $this->importRowsFromTask($task);
            if ($rows === []) {
                throw new \RuntimeException('No employee rows supplied for import.');
            }

            $organizationId = $this->importOrganizationId($task, $user);
            $defaultBranchId = $this->resolveDefaultBranchId($user, $organizationId);
            $created = 0;
            $skipped = 0;
            $failures = [];
            $total = count($rows);
            $seenCodes = [];
            $seenNationalIds = [];
            $seenNames = [];

            foreach ($rows as $index => $row) {
                if (($index + 1) % 5 === 0) {
                    $tasks->assertNotCancelled($task);
                }

                if (! is_array($row)) {
                    continue;
                }

                try {
                    $body = $this->normalizeRow($row, $organizationId);
                    if ($body['first_name'] === '' || $body['last_name'] === '') {
                        throw new \InvalidArgumentException('First name and last name are required.');
                    }

                    $access = app(\App\Services\Auth\UserAccessService::class);
                    $limitedBranch = $access->branchId($user);
                    if ($limitedBranch !== null) {
                        $requestedBranch = isset($body['branch_id']) ? (int) $body['branch_id'] : null;
                        if ($requestedBranch !== null && $requestedBranch !== $limitedBranch) {
                            throw new \InvalidArgumentException(
                                'You can only import employees into your assigned branch.',
                            );
                        }
                        $body['branch_id'] = $limitedBranch;
                    } elseif (! empty($body['branch_id'])) {
                        $access->assertBranchInOrganization($user, (int) $body['branch_id']);
                    } elseif ($defaultBranchId > 0) {
                        $body['branch_id'] = $defaultBranchId;
                    }

                    if (empty($body['branch_id'])) {
                        throw new \InvalidArgumentException('Employee import requires a branch in this organization.');
                    }

                    $branchId = (int) $body['branch_id'];
                    $providedCode = trim((string) ($row['employee_code'] ?? $body['employee_code'] ?? ''));
                    $nationalId = trim((string) ($body['national_id'] ?? ''));
                    $fullName = strtolower(trim(Employee::composeFullName(
                        $body['first_name'],
                        $body['middle_name'] ?? null,
                        $body['last_name'],
                    )));
                    $codeKey = $providedCode !== '' ? strtolower($providedCode) : '';
                    $nationalKey = $nationalId !== '' ? strtolower($nationalId) : '';
                    $nameKey = $fullName.'|'.$branchId;

                    if ($codeKey !== '' && isset($seenCodes[$codeKey])) {
                        $skipped++;

                        continue;
                    }
                    if ($nationalKey !== '' && isset($seenNationalIds[$nationalKey])) {
                        $skipped++;

                        continue;
                    }
                    if (isset($seenNames[$nameKey])) {
                        $skipped++;

                        continue;
                    }

                    if ($this->employeeAlreadyExists($organizationId, $branchId, $codeKey, $nationalKey, $fullName)) {
                        if ($codeKey !== '') {
                            $seenCodes[$codeKey] = true;
                        }
                        if ($nationalKey !== '') {
                            $seenNationalIds[$nationalKey] = true;
                        }
                        $seenNames[$nameKey] = true;
                        $skipped++;

                        continue;
                    }

                    $body['organization_id'] = $organizationId;
                    $body['full_name'] = Employee::composeFullName(
                        $body['first_name'],
                        $body['middle_name'] ?? null,
                        $body['last_name'],
                    );

                    DB::transaction(function () use (&$body, $organizationId): void {
                        if (empty($body['employee_code'])) {
                            $body['employee_code'] = Employee::generateNextEmployeeCode($organizationId);
                        }
                        if (empty($body['payroll_number'])) {
                            $body['payroll_number'] = $body['employee_code'];
                        }

                        Employee::create($body);
                    });

                    if ($codeKey !== '') {
                        $seenCodes[$codeKey] = true;
                    }
                    if ($nationalKey !== '') {
                        $seenNationalIds[$nationalKey] = true;
                    }
                    $seenNames[$nameKey] = true;
                    $created++;
                } catch (\Throwable $e) {
                    if ($this->shouldSkipDuplicateImport($e)) {
                        $skipped++;

                        continue;
                    }

                    $failures[] = [
                        'row' => $index + 1,
                        'code' => trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')) ?: null,
                        'message' => $e->getMessage(),
                    ];
                }

                $this->reportImportLoopProgress($tasks, $task, $index, $total);
            }

            $this->completeImportTask($tasks, $task, $this->buildImportResult($created, $skipped, $failures));
        } catch (\Throwable $e) {
            $this->failImportTask($tasks, $task, $e, 'ImportEmployeesJob');
        }
    }

    protected function resolveDefaultBranchId(User $user, int $organizationId): int
    {
        $access = app(\App\Services\Auth\UserAccessService::class);
        $limitedBranch = $access->branchId($user);
        if ($limitedBranch !== null) {
            return $limitedBranch;
        }

        if (! empty($user->branch_id)) {
            return (int) $user->branch_id;
        }

        return (int) (DB::table('branches')
            ->where('organization_id', $organizationId)
            ->orderByRaw("CASE WHEN branch_code = 'HQ' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->value('id') ?? 0);
    }

    protected function employeeAlreadyExists(
        int $organizationId,
        int $branchId,
        string $codeKey,
        string $nationalKey,
        string $fullName,
    ): bool {
        if ($codeKey !== '') {
            $byCode = Employee::query()
                ->where('organization_id', $organizationId)
                ->whereRaw('LOWER(TRIM(employee_code)) = ?', [$codeKey])
                ->exists();
            if ($byCode) {
                return true;
            }
        }

        if ($nationalKey !== '') {
            $byNationalId = Employee::query()
                ->where('organization_id', $organizationId)
                ->whereRaw('LOWER(TRIM(national_id)) = ?', [$nationalKey])
                ->exists();
            if ($byNationalId) {
                return true;
            }
        }

        if ($fullName === '') {
            return false;
        }

        return Employee::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(TRIM(full_name)) = ?', [$fullName])
            ->exists();
    }

    /** @return array<string, mixed> */
    protected function normalizeRow(array $row, int $organizationId): array
    {
        $body = [
            'first_name' => trim((string) ($row['first_name'] ?? '')),
            'last_name' => trim((string) ($row['last_name'] ?? '')),
            'nationality' => 'Kenyan',
            'country' => 'Kenya',
        ];

        foreach ([
            'middle_name',
            'employee_code',
            'payroll_number',
            'email',
            'personal_email',
            'phone',
            'alt_phone',
            'job_title',
            'national_id',
            'kra_pin',
            'nssf_number',
            'sha_number',
            'housing_levy_number',
            'physical_address',
            'city',
            'county',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== '' && $row[$key] !== null) {
                $body[$key] = trim((string) $row[$key]);
            }
        }

        foreach (['branch_id', 'department_id', 'position_id', 'shift_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== '' && $row[$key] !== null) {
                $body[$key] = (int) $row[$key];
            }
        }

        foreach (['hire_date', 'confirmation_date', 'contract_start_date', 'contract_end_date'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== '' && $row[$key] !== null) {
                $body[$key] = (string) $row[$key];
            }
        }

        if (array_key_exists('base_salary', $row) && $row['base_salary'] !== '' && $row['base_salary'] !== null) {
            $body['base_salary'] = (float) $row['base_salary'];
        }

        $employmentType = strtolower(trim((string) ($row['employment_type'] ?? 'permanent')));
        if (in_array($employmentType, ['permanent', 'contract', 'casual', 'intern'], true)) {
            $body['employment_type'] = $employmentType;
        }

        $employmentStatus = strtolower(trim((string) ($row['employment_status'] ?? 'active')));
        if (in_array($employmentStatus, ['active', 'suspended', 'terminated', 'retired'], true)) {
            $body['employment_status'] = $employmentStatus;
        }
        $body['is_active'] = ($body['employment_status'] ?? 'active') === 'active';

        $gender = strtolower(trim((string) ($row['gender'] ?? '')));
        if (in_array($gender, ['male', 'female', 'other', 'undisclosed'], true)) {
            $body['gender'] = $gender;
        }

        if (! empty($body['hire_date']) && empty($body['probation_end_date'])) {
            $months = (int) (HrPayrollSettingsResolver::forOrganizationId($organizationId)['default_probation_months'] ?? 0);
            if ($months > 0) {
                $body['probation_end_date'] = \Carbon\Carbon::parse($body['hire_date'])
                    ->addMonths($months)
                    ->toDateString();
            }
        }

        return $body;
    }
}
