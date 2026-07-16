<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\FindsOrganizationEmployee;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalance;
use App\Services\Attendance\LeaveBalanceService;
use Illuminate\Http\Request;

class EmployeeLeaveBalanceController extends Controller
{
    use FindsOrganizationEmployee;

    /** GET /employee-leave-balances — remaining balances for all employees in org */
    public function index(Request $request)
    {
        $orgId = (int) $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization required.'], 403);
        }
        $service = app(LeaveBalanceService::class);

        $employees = Employee::query()
            ->where('organization_id', $orgId)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $rows = $employees->map(function (Employee $employee) use ($service) {
            $balance = EmployeeLeaveBalance::forEmployee($employee);
            $summary = $service->summary($employee);

            return [
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'employee_name' => trim($employee->first_name.' '.$employee->last_name),
                'months_of_service' => $service->monthsOfService($employee),
                'system_annual' => round($service->systemAnnualEntitled($employee), 2),
                'system_sick' => round($service->systemSickEntitled($employee), 2),
                'annual_entitled' => $summary['annual']['entitled'],
                'annual_used' => $summary['annual']['used'],
                'annual_available' => $summary['annual']['available'],
                'sick_entitled' => $summary['sick']['entitled'],
                'sick_used' => $summary['sick']['used'],
                'sick_available' => $summary['sick']['available'],
                'off_days_allocated' => (float) $balance->off_days_allocated,
                'off_days_used' => $summary['off_days']['used'],
                'off_days_available' => $summary['off_days']['available'],
                'notes' => $balance->notes,
            ];
        });

        return response()->json(['data' => $rows->values()]);
    }

    /** POST /employee-leave-balances/allocate-off-days — admin only */
    public function allocateOffDays(Request $request)
    {
        if (! $request->user()?->is_admin) {
            return response()->json(['message' => 'Admin access required.'], 403);
        }

        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'days' => 'required|numeric|min:0.5|max:365',
            'notes' => 'nullable|string|max:500',
        ]);

        $employee = $this->findOrgEmployee($data['employee_id'], $request);
        $balance = EmployeeLeaveBalance::forEmployee($employee);
        $balance->off_days_allocated = (float) $balance->off_days_allocated + (float) $data['days'];
        if (! empty($data['notes'])) {
            $balance->notes = trim($data['notes']);
        }
        $balance->save();

        return response()->json($balance->fresh('employee'));
    }

    /** PUT /employees/{employee}/leave-balances — admin only */
    public function update(Request $request, string $employeeId)
    {
        if (! $request->user()?->is_admin) {
            return response()->json(['message' => 'Admin access required.'], 403);
        }

        $employee = $this->findOrgEmployee($employeeId, $request);

        $data = $request->validate([
            'annual_entitled' => 'nullable|numeric|min:0|max:365',
            'sick_entitled' => 'nullable|numeric|min:0|max:365',
            'off_days_allocated' => 'nullable|numeric|min:0|max:365',
            'notes' => 'nullable|string|max:500',
        ]);

        $service = app(LeaveBalanceService::class);
        $balance = $service->applyAdminEntitlements($employee, $data);

        return response()->json($this->balancePayload($employee, $service, $balance));
    }

    /** @return array<string, mixed> */
    protected function balancePayload(
        Employee $employee,
        LeaveBalanceService $service,
        EmployeeLeaveBalance $balance,
    ): array {
        return [
            'employee_id' => $employee->id,
            'months_of_service' => $service->monthsOfService($employee),
            'system' => [
                'annual_entitled' => round($service->systemAnnualEntitled($employee), 2),
                'sick_entitled' => round($service->systemSickEntitled($employee), 2),
            ],
            'adjustments' => [
                'annual' => (float) $balance->annual_adjustment,
                'sick' => (float) $balance->sick_adjustment,
            ],
            'off_days_allocated' => (float) $balance->off_days_allocated,
            'notes' => $balance->notes,
            'balances' => $service->summary($employee),
        ];
    }
}
