<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Attendance\LeaveBalanceService;
use App\Services\Auth\UserAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HrReportController extends Controller
{
    public function leaveBalance(Request $request)
    {
        $filters = $this->filters($request);
        $service = app(LeaveBalanceService::class);

        $query = Employee::query()
            ->with([
                'department:id,department_name',
                'branch:id,branch_name',
            ])
            ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['employment_status'] ?? null, fn ($q, $status) => $q->where('employment_status', $status))
            ->orderBy('full_name');

        $paginator = $query->paginate(min((int) ($filters['per_page'] ?? 50), 200));

        $paginator->getCollection()->transform(function (Employee $employee) use ($service) {
            $summary = $service->summary($employee);

            return [
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'department_name' => $employee->department?->department_name,
                'branch_name' => $employee->branch?->branch_name,
                'employment_status' => $employee->employment_status,
                'annual_entitled' => $summary['annual']['entitled'],
                'annual_used' => $summary['annual']['used'],
                'annual_available' => $summary['annual']['available'],
                'sick_entitled' => $summary['sick']['entitled'],
                'sick_used' => $summary['sick']['used'],
                'sick_available' => $summary['sick']['available'],
                'off_days_entitled' => $summary['off_days']['entitled'],
                'off_days_used' => $summary['off_days']['used'],
                'off_days_available' => $summary['off_days']['available'],
            ];
        });

        return response()->json($paginator);
    }

    public function statutoryDeductions(Request $request)
    {
        return response()->json($this->reportFromView('v_statutory_deductions', $this->filters($request), [
            'organization_id', 'branch_id', 'payroll_run_id', 'period_code', 'payroll_status',
        ]));
    }

    public function bankTransfer(Request $request)
    {
        return response()->json($this->reportFromView('v_bank_transfer_report', $this->filters($request), [
            'organization_id', 'branch_id', 'payroll_run_id', 'period_code', 'payroll_status',
        ]));
    }

    public function nssfRemittance(Request $request)
    {
        return response()->json($this->reportFromView('v_nssf_remittance_report', $this->filters($request), [
            'organization_id', 'branch_id', 'payroll_run_id', 'period_code', 'payroll_status',
        ]));
    }

    public function otherDeductionsByPeriod(Request $request)
    {
        return response()->json($this->reportFromView('v_other_deductions_by_period', $this->filters($request), [
            'organization_id', 'branch_id', 'payroll_run_id', 'period_code', 'payroll_status',
        ]));
    }

    public function headcount(Request $request)
    {
        return response()->json($this->reportFromView('v_headcount_report', $this->filters($request), [
            'organization_id', 'branch_id', 'department_id', 'employment_status', 'employment_type', 'is_active',
        ]));
    }

    public function contractExpiry(Request $request)
    {
        $filters = $this->filters($request);
        $q = DB::table('v_contract_expiry');

        foreach (['organization_id', 'branch_id', 'department_name', 'employment_type'] as $col) {
            if (! empty($filters[$col])) {
                $q->where($col, $filters[$col]);
            }
        }

        if (! empty($filters['from_date'])) {
            $q->where('contract_end_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $q->where('contract_end_date', '<=', $filters['to_date']);
        }
        if (isset($filters['days_until_expiry']) && $filters['days_until_expiry'] !== '') {
            $q->where('days_until_expiry', '<=', (int) $filters['days_until_expiry']);
        }

        return response()->json(
            $q->orderBy('contract_end_date')->paginate(min((int) ($filters['per_page'] ?? 50), 200))
        );
    }

    public function staffTurnover(Request $request)
    {
        return response()->json($this->reportFromView('v_staff_turnover', $this->filters($request), [
            'organization_id', 'branch_id', 'department_id',
        ]));
    }

    public function hrDashboardKpi(Request $request)
    {
        $filters = $this->filters($request);
        $q = DB::table('v_hr_dashboard_kpi');

        if (! empty($filters['organization_id'])) {
            $q->where('organization_id', $filters['organization_id']);
        }

        // v_hr_dashboard_kpi is org-level (no branch_id column). Branch isolation
        // for transactional HR lists is enforced on employees / attendance / leave APIs.

        return response()->json(
            $q->paginate(min((int) ($filters['per_page'] ?? 50), 200))
        );
    }

    /** Daily attendance register (present / late / absent metrics). */
    public function attendanceRegister(Request $request)
    {
        $filters = $this->filters($request);
        $q = DB::table('employee_attendance as a')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')
            ->select([
                'a.id',
                'a.attendance_date',
                'a.employee_id',
                'e.employee_code',
                'e.full_name',
                'd.department_name',
                'b.branch_name',
                'a.check_in',
                'a.check_out',
                'a.status',
                'a.hours_worked',
                'a.expected_hours',
                'a.late_minutes',
                'a.lateness_waived',
                'a.lateness_waiver_reason',
                'a.lunch_status',
                'a.lunch_minutes',
                'a.early_leave_minutes',
                'a.overtime_minutes',
                'a.source',
                'a.notes',
            ])
            ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('a.organization_id', $id))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('a.branch_id', $id))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('e.department_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('a.status', $status))
            ->when($filters['from_date'] ?? null, fn ($q, $d) => $q->whereDate('a.attendance_date', '>=', $d))
            ->when($filters['to_date'] ?? null, fn ($q, $d) => $q->whereDate('a.attendance_date', '<=', $d))
            ->orderByDesc('a.attendance_date')
            ->orderBy('e.full_name');

        return response()->json($q->paginate(min((int) ($filters['per_page'] ?? 50), 200)));
    }

    /** Lateness list — days with late_minutes > 0 (includes waived). */
    public function latenessList(Request $request)
    {
        $filters = $this->filters($request);
        $q = DB::table('employee_attendance as a')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')
            ->select([
                'a.id',
                'a.attendance_date',
                'a.employee_id',
                'e.employee_code',
                'e.full_name',
                'd.department_name',
                'b.branch_name',
                'a.check_in',
                'a.check_out',
                'a.status',
                'a.late_minutes',
                'a.lateness_waived',
                'a.lateness_waiver_reason',
                'a.lateness_waived_at',
                'a.hours_worked',
                'a.expected_hours',
                'a.source',
                'a.notes',
            ])
            ->where('a.late_minutes', '>', 0)
            ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('a.organization_id', $id))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('a.branch_id', $id))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('e.department_id', $id))
            ->when($filters['from_date'] ?? null, fn ($q, $d) => $q->whereDate('a.attendance_date', '>=', $d))
            ->when($filters['to_date'] ?? null, fn ($q, $d) => $q->whereDate('a.attendance_date', '<=', $d))
            ->when(isset($filters['lateness_waived']) && $filters['lateness_waived'] !== '', function ($q) use ($filters) {
                $q->where('a.lateness_waived', filter_var($filters['lateness_waived'], FILTER_VALIDATE_BOOLEAN));
            })
            ->orderByDesc('a.attendance_date')
            ->orderByDesc('a.late_minutes')
            ->orderBy('e.full_name');

        return response()->json($q->paginate(min((int) ($filters['per_page'] ?? 50), 200)));
    }

    protected function filters(Request $request): array
    {
        $filters = $request->only([
            'branch_id', 'department_id', 'employment_status', 'employment_type', 'is_active',
            'from_date', 'to_date', 'date_column', 'per_page', 'organization_id',
            'payroll_run_id', 'period_code', 'status', 'days_until_expiry', 'lateness_waived',
        ]);

        $user = $request->user();
        if ($user) {
            if (empty($filters['organization_id']) && $user->organization_id) {
                $filters['organization_id'] = $user->organization_id;
            }
            if (empty($filters['branch_id'])) {
                $branchId = app(UserAccessService::class)->branchId($user);
                if ($branchId !== null) {
                    $filters['branch_id'] = $branchId;
                }
            }
        }

        return $filters;
    }

    protected function reportFromView(string $view, array $filters, array $allowedCols)
    {
        $q = DB::table($view);
        foreach ($allowedCols as $col) {
            if (isset($filters[$col]) && $filters[$col] !== '') {
                $q->where($col, $filters[$col]);
            }
        }
        if (! empty($filters['from_date']) && ! empty($filters['date_column'])) {
            $dateColumn = (string) $filters['date_column'];
            if ($this->viewColumnExists($view, $dateColumn)) {
                $q->where($dateColumn, '>=', $filters['from_date']);
            }
        }
        if (! empty($filters['to_date']) && ! empty($filters['date_column'])) {
            $dateColumn = (string) $filters['date_column'];
            if ($this->viewColumnExists($view, $dateColumn)) {
                $q->where($dateColumn, '<=', $filters['to_date']);
            }
        }

        return $q->paginate(min((int) ($filters['per_page'] ?? 50), 200));
    }

    protected function viewColumnExists(string $view, string $column): bool
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            return false;
        }

        static $cache = [];
        $key = "{$view}.{$column}";
        if (! array_key_exists($key, $cache)) {
            $cache[$key] = collect(DB::select(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
                 LIMIT 1',
                [$view, $column],
            ))->isNotEmpty();
        }

        return $cache[$key];
    }
}
