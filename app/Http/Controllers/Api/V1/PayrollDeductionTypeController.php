<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\EmployeeDeduction;
use App\Models\PayrollDeductionType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayrollDeductionTypeController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return PayrollDeductionType::class;
    }

    public function index(Request $request)
    {
        $status = strtolower(trim((string) $request->input('payroll_status', 'pending')));
        if (! in_array($status, ['pending', 'applied', 'all'], true)) {
            $status = 'pending';
        }

        $query = PayrollDeductionType::query()
            ->with([
                'employeeDeductions' => fn ($q) => $q
                    ->with([
                        'employee:id,first_name,middle_name,last_name,full_name,employee_code',
                        'payrollRun:id,run_date,pay_period_id,status',
                        'payrollRun.payPeriod:id,period_code,period_start,period_end',
                    ])
                    ->orderBy('id'),
            ])
            ->withCount('employeeDeductions');

        $user = $request->user();
        if ($user && $this->modelHasColumn('organization_id') && ! $this->shouldSkipOrganizationScope($user, $request)) {
            $this->access()->scopeOrganization($query, $user, 'organization_id', $request);
        }
        if ($user && $this->modelHasColumn('branch_id')) {
            $this->access()->applyBranchListFilter($query, $user, $request);
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if ($col === 'branch_id') {
                continue;
            }
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = $request->input('q')) {
            $this->applySearch($query, $q);
        }

        // Prefer open/pending types for the next payroll; applied one-time stay under Applied / All.
        if ($status === 'pending') {
            $query->where(function ($outer) {
                $outer->where(function ($q) {
                    $q->where('is_active', true)
                        ->where(function ($inner) {
                            $inner->where('frequency', '!=', PayrollDeductionType::FREQUENCY_ONE_TIME)
                                ->orWhereNull('frequency')
                                ->orWhere('frequency', PayrollDeductionType::FREQUENCY_PER_CYCLE);
                        });
                })->orWhere(function ($q) {
                    $q->where('frequency', PayrollDeductionType::FREQUENCY_ONE_TIME)
                        ->where(function ($inner) {
                            $inner->where(function ($all) {
                                $all->where('applies_to_all', true)->where('is_active', true);
                            })->orWhereHas('employeeDeductions', function ($ed) {
                                $ed->where('is_active', true)->whereNull('payroll_run_id');
                            });
                        });
                });
            });
        } elseif ($status === 'applied') {
            $query->where('frequency', PayrollDeductionType::FREQUENCY_ONE_TIME)
                ->where(function ($q) {
                    $q->where('is_active', false)
                        ->orWhereHas('employeeDeductions', function ($ed) {
                            $ed->whereNotNull('payroll_run_id');
                        });
                })
                ->whereDoesntHave('employeeDeductions', function ($ed) {
                    $ed->where('is_active', true)->whereNull('payroll_run_id');
                });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $paginator->getCollection()->transform(
            fn (PayrollDeductionType $type) => $this->typeWithAssignees($type, $status)
        );

        return response()->json($paginator);
    }

    public function show(string $id)
    {
        $type = $this->findScoped($id);
        $type->load([
            'employeeDeductions' => fn ($q) => $q
                ->with([
                    'employee:id,first_name,middle_name,last_name,full_name,employee_code',
                    'payrollRun:id,run_date,pay_period_id,status',
                    'payrollRun.payPeriod:id,period_code,period_start,period_end',
                ])
                ->orderBy('id'),
        ]);
        $type->loadCount('employeeDeductions');

        return response()->json($this->typeWithAssignees($type));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $employeeIds = array_values(array_unique(array_map('intval', $data['employee_ids'] ?? [])));
        unset($data['employee_ids']);

        $user = $request->user();
        if ($user && $this->modelHasColumn('organization_id') && empty($data['organization_id'])) {
            $data['organization_id'] = $user->organization_id;
        }
        if ($user && $this->modelHasColumn('branch_id')) {
            $this->applyBranchScopeToWriteData($user, $data, $request);
        }

        if ($employeeIds !== []) {
            $data['applies_to_all'] = false;
        }

        $assigned = 0;
        try {
            $model = DB::transaction(function () use ($request, $data, $employeeIds, &$assigned) {
                $type = PayrollDeductionType::create($data);
                $assigned = $this->syncAssignees($request, $type, $employeeIds, replace: true);

                return $type;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'deduction_code' => ['This deduction code is already used in your organization.'],
            ]);
        }

        $model->load([
            'employeeDeductions' => fn ($q) => $q
                ->with([
                    'employee:id,first_name,middle_name,last_name,full_name,employee_code',
                    'payrollRun:id,run_date,pay_period_id,status',
                    'payrollRun.payPeriod:id,period_code,period_start,period_end',
                ])
                ->orderBy('id'),
        ]);
        $model->loadCount('employeeDeductions');

        return response()->json(array_merge($this->typeWithAssignees($model)->toArray(), [
            'assigned_employee_count' => $assigned,
        ]), 201);
    }

    public function update(Request $request, string $id)
    {
        $model = $this->findScoped($id);
        $data = $this->validated($request, updating: true, existing: $model);
        $employeeIds = array_key_exists('employee_ids', $data)
            ? array_values(array_unique(array_map('intval', $data['employee_ids'] ?? [])))
            : null;
        unset($data['employee_ids']);

        $user = $request->user();
        if ($user && $this->modelHasColumn('branch_id')) {
            $this->applyBranchScopeToWriteData($user, $data, $request);
        }

        if (is_array($employeeIds) && $employeeIds !== []) {
            $data['applies_to_all'] = false;
        }

        $assigned = 0;
        try {
            DB::transaction(function () use ($request, $model, $data, $employeeIds, &$assigned) {
                $model->update($data);
                if (is_array($employeeIds)) {
                    $assigned = $this->syncAssignees($request, $model->fresh(), $employeeIds, replace: true);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'deduction_code' => ['This deduction code is already used in your organization.'],
            ]);
        }

        $fresh = $model->fresh();
        $fresh->load([
            'employeeDeductions' => fn ($q) => $q
                ->with([
                    'employee:id,first_name,middle_name,last_name,full_name,employee_code',
                    'payrollRun:id,run_date,pay_period_id,status',
                    'payrollRun.payPeriod:id,period_code,period_start,period_end',
                ])
                ->orderBy('id'),
        ]);
        $fresh->loadCount('employeeDeductions');

        return response()->json(array_merge($this->typeWithAssignees($fresh)->toArray(), [
            'assigned_employee_count' => $assigned,
        ]));
    }

    /**
     * Attach assignee + payroll status fields for the list / show payload.
     */
    protected function typeWithAssignees(PayrollDeductionType $type, ?string $listStatus = null): PayrollDeductionType
    {
        $assignees = [];
        $pendingCount = 0;
        $appliedCount = 0;
        $appliedDates = [];
        $periodLabels = [];

        foreach ($type->employeeDeductions ?? [] as $row) {
            if ($row->isPendingForPayroll()) {
                $pendingCount++;
            } elseif ($row->payroll_run_id) {
                $appliedCount++;
                $run = $row->relationLoaded('payrollRun') ? $row->payrollRun : null;
                if ($run?->run_date) {
                    $appliedDates[] = $run->run_date instanceof \DateTimeInterface
                        ? $run->run_date->format('Y-m-d')
                        : (string) $run->run_date;
                }
                $period = $run?->payPeriod;
                if ($period) {
                    $code = trim((string) ($period->period_code ?? ''));
                    if ($code !== '') {
                        $periodLabels[] = $code;
                    } else {
                        $start = $period->period_start instanceof \DateTimeInterface
                            ? $period->period_start->format('Y-m-d')
                            : (string) ($period->period_start ?? '');
                        $end = $period->period_end instanceof \DateTimeInterface
                            ? $period->period_end->format('Y-m-d')
                            : (string) ($period->period_end ?? '');
                        $label = trim($start.' – '.$end, ' –');
                        if ($label !== '') {
                            $periodLabels[] = $label;
                        }
                    }
                }
            }

            if ($type->applies_to_all) {
                continue;
            }
            $employee = $row->employee;
            if (! $employee) {
                continue;
            }
            $name = trim(implode(' ', array_filter([
                (string) ($employee->first_name ?? ''),
                (string) ($employee->middle_name ?? ''),
                (string) ($employee->last_name ?? ''),
            ])));
            if ($name === '') {
                $name = trim((string) ($employee->full_name ?? ''));
            }
            $assignees[] = [
                'id' => (int) $employee->id,
                'name' => $name !== '' ? $name : ('Employee #'.$employee->id),
                'employee_code' => $employee->employee_code ?? null,
            ];
        }

        $isOneTime = $type->isOneTime();
        if ($isOneTime) {
            if ($pendingCount > 0 || ($type->applies_to_all && $type->is_active)) {
                $payrollStatus = 'pending';
            } elseif ($appliedCount > 0 || ! $type->is_active) {
                $payrollStatus = 'applied';
            } else {
                $payrollStatus = $type->is_active ? 'pending' : 'inactive';
            }
        } else {
            $payrollStatus = $type->is_active ? 'active' : 'inactive';
        }

        $uniqueDates = array_values(array_unique(array_filter($appliedDates)));
        sort($uniqueDates);
        $uniquePeriods = array_values(array_unique(array_filter($periodLabels)));

        $type->setAttribute('assigned_employees', $assignees);
        $type->setAttribute(
            'assigned_employee_count',
            $type->applies_to_all
                ? null
                : (int) ($type->employee_deductions_count ?? count($assignees)),
        );
        $type->setAttribute('payroll_status', $payrollStatus);
        $type->setAttribute('pending_assignee_count', $pendingCount);
        $type->setAttribute('applied_assignee_count', $appliedCount);
        $type->setAttribute('applied_on', $uniqueDates[0] ?? null);
        $type->setAttribute('applied_on_dates', $uniqueDates);
        $type->setAttribute('applied_period', $uniquePeriods[0] ?? null);
        $type->setAttribute('applied_periods', $uniquePeriods);
        if ($listStatus !== null) {
            $type->setAttribute('list_filter', $listStatus);
        }
        // Keep list payloads lean — nested deductions are only used to build the summary.
        $type->unsetRelation('employeeDeductions');

        return $type;
    }

    /**
     * Create / keep employee_deductions for the given employees.
     * When $replace is true, remove active assignments that are no longer selected
     * (skip one-time rows already applied on a payroll run).
     *
     * @param  list<int>  $employeeIds
     */
    protected function syncAssignees(
        Request $request,
        PayrollDeductionType $type,
        array $employeeIds,
        bool $replace = false,
    ): int {
        if ($type->applies_to_all) {
            if ($replace) {
                // Org-wide types don't need per-employee rows — drop open assignments.
                EmployeeDeduction::query()
                    ->where('deduction_type_id', $type->id)
                    ->whereNull('payroll_run_id')
                    ->delete();
            }

            return 0;
        }

        if ($employeeIds === []) {
            if ($replace) {
                EmployeeDeduction::query()
                    ->where('deduction_type_id', $type->id)
                    ->whereNull('payroll_run_id')
                    ->delete();
            }

            return 0;
        }

        $orgId = (int) ($type->organization_id ?: $request->user()?->organization_id ?? 0);
        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->when($orgId > 0, fn ($q) => $q->where('organization_id', $orgId))
            ->get();

        if ($employees->count() !== count($employeeIds)) {
            abort(422, 'One or more selected employees were not found in your organization.');
        }

        $existing = EmployeeDeduction::query()
            ->where('deduction_type_id', $type->id)
            ->get()
            ->keyBy(fn (EmployeeDeduction $row) => (int) $row->employee_id);

        $keepIds = array_fill_keys($employeeIds, true);
        $created = 0;

        foreach ($employees as $employee) {
            $employeeId = (int) $employee->id;
            if ($existing->has($employeeId)) {
                $row = $existing->get($employeeId);
                // Refresh template fields on open assignments (not yet applied one-time).
                if (! $row->payroll_run_id) {
                    $row->update([
                        'name' => $type->name,
                        'calc_type' => $type->calc_type ?: 'fixed',
                        'amount' => $type->calc_type === 'percentage' ? 0 : (float) $type->default_amount,
                        'percentage' => $type->calc_type === 'percentage' ? (float) $type->default_percentage : null,
                        'is_active' => (bool) $type->is_active,
                        'frequency' => $type->isOneTime()
                            ? EmployeeDeduction::FREQUENCY_ONE_TIME
                            : EmployeeDeduction::FREQUENCY_PER_CYCLE,
                    ]);
                }
                continue;
            }

            EmployeeDeduction::create([
                'employee_id' => $employee->id,
                'branch_id' => $employee->branch_id,
                'deduction_type_id' => $type->id,
                'name' => $type->name,
                'calc_type' => $type->calc_type ?: 'fixed',
                'amount' => $type->calc_type === 'percentage' ? 0 : (float) $type->default_amount,
                'percentage' => $type->calc_type === 'percentage' ? (float) $type->default_percentage : null,
                'is_active' => (bool) $type->is_active,
                'frequency' => $type->isOneTime()
                    ? EmployeeDeduction::FREQUENCY_ONE_TIME
                    : EmployeeDeduction::FREQUENCY_PER_CYCLE,
            ]);
            $created++;
        }

        if ($replace) {
            foreach ($existing as $employeeId => $row) {
                if (isset($keepIds[$employeeId])) {
                    continue;
                }
                // Never delete a one-time deduction already applied on a payroll run.
                if ($row->payroll_run_id) {
                    continue;
                }
                $row->delete();
            }
        }

        return $created + count(array_intersect_key($keepIds, $existing->all()));
    }

    protected function validated(Request $request, bool $updating = false, ?PayrollDeductionType $existing = null): array
    {
        $req = $updating ? 'sometimes|' : 'required|';
        $user = $request->user();
        $orgId = (int) ($user?->organization_id
            ?? $request->input('organization_id')
            ?? $existing?->organization_id
            ?? 0);
        $ignoreId = $existing?->id;

        if ($request->exists('deduction_code')) {
            $request->merge([
                'deduction_code' => strtoupper(trim((string) $request->input('deduction_code'))),
            ]);
        }

        return $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'deduction_code' => [
                $updating ? 'sometimes' : 'required',
                'string',
                'max:45',
                Rule::unique('payroll_deduction_types', 'deduction_code')
                    ->where(fn ($q) => $q->where('organization_id', $orgId))
                    ->ignore($ignoreId),
            ],
            'name' => $req . 'string|max:200',
            'calc_type' => 'nullable|in:fixed,percentage',
            'default_amount' => 'nullable|numeric|min:0',
            'default_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'applies_to_all' => 'nullable|boolean',
            'frequency' => 'nullable|in:per_cycle,one_time',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer|exists:employees,id',
        ], [
            'deduction_code.unique' => 'This deduction code is already used in your organization.',
        ]);
    }
}
