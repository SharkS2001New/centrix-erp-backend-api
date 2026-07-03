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

        return response()->json(
            $q->paginate(min((int) ($filters['per_page'] ?? 50), 200))
        );
    }

    protected function filters(Request $request): array
    {
        $filters = $request->only([
            'branch_id', 'department_id', 'employment_status', 'employment_type', 'is_active',
            'from_date', 'to_date', 'date_column', 'per_page', 'organization_id',
            'payroll_run_id', 'period_code', 'status', 'days_until_expiry',
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
            $q->where($filters['date_column'], '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date']) && ! empty($filters['date_column'])) {
            $q->where($filters['date_column'], '<=', $filters['to_date']);
        }

        return $q->paginate(min((int) ($filters['per_page'] ?? 50), 200));
    }
}
