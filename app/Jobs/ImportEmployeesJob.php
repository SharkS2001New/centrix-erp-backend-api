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
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ImportEmployeesJob implements ShouldBeUnique, ShouldQueue
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
                throw new \RuntimeException('User not found for employee import task.');
            }

            $rows = $this->importRowsFromTask($task);
            if ($rows === []) {
                throw new \RuntimeException('No employee rows supplied for import.');
            }

            $organizationId = $this->importOrganizationId($task, $user);
            $created = 0;
            $skipped = 0;
            $failures = [];
            $total = count($rows);
            $seenCodes = [];

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

                    $providedCode = trim((string) ($row['employee_code'] ?? ''));
                    if ($providedCode !== '') {
                        $codeKey = strtolower($providedCode);
                        if (isset($seenCodes[$codeKey])) {
                            $skipped++;

                            continue;
                        }

                        if (Employee::query()
                            ->where('organization_id', $organizationId)
                            ->whereRaw('LOWER(TRIM(employee_code)) = ?', [$codeKey])
                            ->exists()) {
                            $seenCodes[$codeKey] = true;
                            $skipped++;

                            continue;
                        }
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

                    if ($providedCode !== '') {
                        $seenCodes[strtolower($providedCode)] = true;
                    }
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
