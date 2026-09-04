<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Attendance\AttendanceDayReconciler;
use App\Services\Payroll\PayrollEarningsService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PayrollAbsentCorrectedToPresentTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_correcting_absent_to_present_without_punches_stops_absent_deduction(): void
    {
        $this->travelTo('2026-08-20 10:00:00');

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'ABS'.strtoupper(substr(uniqid(), -6)),
            'shift_name' => 'Absent Fix',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'crosses_midnight' => false,
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'work_weekdays' => [1, 2, 3, 4, 5],
            'is_active' => true,
        ]);

        $employee = Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP#ABS'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#ABS'.strtoupper(uniqid()),
            'first_name' => 'Absent',
            'last_name' => 'Fix',
            'full_name' => 'Absent Fix',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 30000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        $date = '2026-08-18'; // Tuesday
        $reconciler = app(AttendanceDayReconciler::class);
        $absent = $reconciler->reconcileManualSpan(
            $employee->fresh('shift'),
            $date,
            null,
            null,
            'manual',
            null,
            $employee->branch_id ? (int) $employee->branch_id : null,
            'Auto-marked absent (no attendance recorded)',
            'absent',
            null,
        );
        $this->assertSame('absent', $absent->status);

        $before = app(PayrollEarningsService::class)->summarizeAttendance(
            $employee->fresh('shift'),
            '2026-08-18',
            '2026-08-18',
        );
        $this->assertSame(1.0, $before['absent_days']);
        $this->assertSame(0.0, $before['paid_days']);

        $present = $reconciler->reconcileManualSpan(
            $employee->fresh('shift'),
            $date,
            null,
            null,
            'manual',
            null,
            $employee->branch_id ? (int) $employee->branch_id : null,
            'Auto-marked absent (no attendance recorded)',
            'present',
            null,
        );
        $this->assertSame('present', $present->status);
        $this->assertGreaterThan(0.0, (float) $present->hours_worked);
        $this->assertStringContainsString('Corrected to present', (string) $present->notes);

        $after = app(PayrollEarningsService::class)->summarizeAttendance(
            $employee->fresh('shift'),
            '2026-08-18',
            '2026-08-18',
        );
        $this->assertSame(0.0, $after['absent_days']);
        $this->assertSame(1.0, $after['paid_days']);

        $period = PayPeriod::query()->create([
            'organization_id' => $org->id,
            'period_code' => 'ABS'.uniqid(),
            'period_start' => '2026-08-18',
            'period_end' => '2026-08-18',
            'status' => 'open',
        ]);
        $line = app(PayrollEarningsService::class)->buildLineInput(
            $employee->fresh('shift'),
            $period,
            [
                'include_allowances' => false,
                'include_other_deductions' => false,
                'include_overtime' => false,
                'use_attendance_proration' => true,
            ],
        );
        $this->assertNotNull($line);
        $this->assertEquals(0.0, (float) ($line['payroll_meta']['absent_amount'] ?? -1));
        $this->assertEquals(30000.0, (float) $line['payroll_meta']['period_basic']);
    }

    public function test_deleting_absent_without_replacement_still_counts_as_unpaid(): void
    {
        $this->travelTo('2026-08-20 10:00:00');

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'DEL'.strtoupper(substr(uniqid(), -6)),
            'shift_name' => 'Delete Absent',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'crosses_midnight' => false,
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'work_weekdays' => [1, 2, 3, 4, 5],
            'is_active' => true,
        ]);

        $employee = Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP#DEL'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#DEL'.strtoupper(uniqid()),
            'first_name' => 'Delete',
            'last_name' => 'Absent',
            'full_name' => 'Delete Absent',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 30000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        $row = app(AttendanceDayReconciler::class)->reconcileManualSpan(
            $employee->fresh('shift'),
            '2026-08-18',
            null,
            null,
            'manual',
            null,
            null,
            'Auto-marked absent (no attendance recorded)',
            'absent',
            null,
        );
        $row->delete();

        $summary = app(PayrollEarningsService::class)->summarizeAttendance(
            $employee->fresh('shift'),
            '2026-08-18',
            '2026-08-18',
        );
        $this->assertSame(1.0, $summary['absent_days']);
        $this->assertSame(0.0, $summary['paid_days']);
    }
}
