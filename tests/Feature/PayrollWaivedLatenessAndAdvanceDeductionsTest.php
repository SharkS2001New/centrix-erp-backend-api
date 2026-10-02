<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeCashAdvance;
use App\Models\Organization;
use App\Models\PayPeriod;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Payroll\PayrollEarningsService;
use App\Services\Payroll\PayrollOtherDeductionsBuilder;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PayrollWaivedLatenessAndAdvanceDeductionsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_present_day_is_full_day_not_fractional_absent(): void
    {
        $this->travelTo('2026-09-02 18:00:00');

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'FUL'.strtoupper(substr(uniqid(), -6)),
            'shift_name' => 'Full Day',
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
            'employee_code' => 'EMP#FUL'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#FUL'.strtoupper(uniqid()),
            'first_name' => 'Full',
            'last_name' => 'Day',
            'full_name' => 'Full Day',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 30000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        // Present with short hours — must still be 1.0 paid day, 0 absent (not 0.05).
        EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => '2026-09-01',
            'status' => 'present',
            'source' => 'clock_device',
            'hours_worked' => 7.6,
            'expected_hours' => 8.0,
            'late_minutes' => 0,
            'lunch_late_minutes' => 0,
            'lateness_waived' => false,
            'early_leave_minutes' => 10,
        ]);

        $summary = app(PayrollEarningsService::class)->summarizeAttendance(
            $employee->fresh('shift'),
            '2026-09-01',
            '2026-09-01',
        );

        $this->assertSame(0.0, $summary['absent_days']);
        $this->assertSame(1.0, $summary['paid_days']);
        $this->assertSame(0, $summary['late_minutes_total']);
    }

    public function test_half_day_status_counts_half_not_hour_fraction(): void
    {
        $this->travelTo('2026-09-02 18:00:00');

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'HLF'.strtoupper(substr(uniqid(), -6)),
            'shift_name' => 'Half Day',
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
            'employee_code' => 'EMP#HLF'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#HLF'.strtoupper(uniqid()),
            'first_name' => 'Half',
            'last_name' => 'Day',
            'full_name' => 'Half Day',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 30000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => '2026-09-01',
            'status' => 'half_day',
            'source' => 'clock_device',
            'hours_worked' => 4.0,
            'expected_hours' => 8.0,
            'late_minutes' => 0,
            'lunch_late_minutes' => 0,
            'lateness_waived' => false,
        ]);

        $summary = app(PayrollEarningsService::class)->summarizeAttendance(
            $employee->fresh('shift'),
            '2026-09-01',
            '2026-09-01',
        );

        $this->assertSame(0.5, $summary['paid_days']);
        $this->assertSame(0.5, $summary['absent_days']);
    }

    public function test_waived_lateness_does_not_create_absent_or_hourly_hold(): void
    {
        $this->travelTo('2026-09-02 18:00:00');

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'WAV'.strtoupper(substr(uniqid(), -6)),
            'shift_name' => 'Waiver Shift',
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
            'employee_code' => 'EMP#WAV'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#WAV'.strtoupper(uniqid()),
            'first_name' => 'Stephen',
            'last_name' => 'Waived',
            'full_name' => 'Stephen Waived',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 10500,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => '2026-09-01',
            'status' => 'present',
            'source' => 'clock_device',
            'hours_worked' => 7.5,
            'expected_hours' => 8.0,
            'late_minutes' => 30,
            'lunch_late_minutes' => 0,
            'lateness_waived' => true,
            'early_leave_minutes' => 0,
        ]);

        $summary = app(PayrollEarningsService::class)->summarizeAttendance(
            $employee->fresh('shift'),
            '2026-09-01',
            '2026-09-01',
        );

        $this->assertSame(0.0, $summary['absent_days']);
        $this->assertSame(0, $summary['late_minutes_total']);
        $this->assertSame(1.0, $summary['paid_days']);
    }

    public function test_unwaived_lateness_deducts_by_hour_not_fractional_absent_day(): void
    {
        $this->travelTo('2026-09-02 18:00:00');

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'HR'.strtoupper(substr(uniqid(), -6)),
            'shift_name' => 'Hour Late',
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
            'employee_code' => 'EMP#HR'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#HR'.strtoupper(uniqid()),
            'first_name' => 'Late',
            'last_name' => 'Hour',
            'full_name' => 'Late Hour',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 30000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => '2026-09-01',
            'status' => 'late',
            'source' => 'clock_device',
            'hours_worked' => 7.5,
            'expected_hours' => 8.0,
            'late_minutes' => 30,
            'lunch_late_minutes' => 0,
            'lateness_waived' => false,
        ]);

        $period = PayPeriod::query()->create([
            'organization_id' => $org->id,
            'period_code' => 'HR'.uniqid(),
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-01',
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
        $this->assertSame(0.0, (float) $line['payroll_meta']['absent_days']);
        $this->assertSame(30, (int) $line['payroll_meta']['late_minutes_total']);
        $this->assertGreaterThan(0.0, (float) $line['payroll_meta']['lateness_amount']);
    }

    public function test_waived_lateness_also_skips_early_leave_hourly_hold(): void
    {
        $this->travelTo('2026-09-02 18:00:00');

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'EWL'.strtoupper(substr(uniqid(), -6)),
            'shift_name' => 'Early Leave Waived',
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
            'employee_code' => 'EMP#EWL'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#EWL'.strtoupper(uniqid()),
            'first_name' => 'Early',
            'last_name' => 'Waived',
            'full_name' => 'Early Waived',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 30000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => '2026-09-01',
            'status' => 'present',
            'source' => 'clock_device',
            'hours_worked' => 7.0,
            'expected_hours' => 8.0,
            'late_minutes' => 20,
            'lunch_late_minutes' => 0,
            'lateness_waived' => true,
            'early_leave_minutes' => 40,
        ]);

        $period = PayPeriod::query()->create([
            'organization_id' => $org->id,
            'period_code' => 'EWL'.uniqid(),
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-01',
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
        $this->assertSame(0, (int) $line['payroll_meta']['late_minutes_total']);
        $this->assertSame(0.0, (float) $line['payroll_meta']['lateness_amount']);
        $this->assertSame(30000.0, (float) $line['payroll_meta']['period_basic']);
    }

    public function test_september_payroll_only_counts_september_attendance_and_respects_waivers(): void
    {
        $this->travelTo('2026-10-02 18:00:00');

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'SEP'.strtoupper(substr(uniqid(), -6)),
            'shift_name' => 'September Scope',
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
            'employee_code' => 'EMP#SEP'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#SEP'.strtoupper(uniqid()),
            'first_name' => 'September',
            'last_name' => 'Scope',
            'full_name' => 'September Scope',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 30000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        foreach (['2026-08-31', '2026-10-01'] as $outsideDate) {
            EmployeeAttendance::query()->create([
                'employee_id' => $employee->id,
                'organization_id' => $org->id,
                'branch_id' => $admin->branch_id,
                'attendance_date' => $outsideDate,
                'status' => 'late',
                'source' => 'clock_device',
                'hours_worked' => 7.0,
                'expected_hours' => 8.0,
                'late_minutes' => 120,
                'lunch_late_minutes' => 0,
                'lateness_waived' => false,
            ]);
        }

        EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => '2026-09-02',
            'status' => 'late',
            'source' => 'clock_device',
            'hours_worked' => 7.5,
            'expected_hours' => 8.0,
            'late_minutes' => 45,
            'lunch_late_minutes' => 0,
            'lateness_waived' => true,
        ]);

        EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => '2026-09-03',
            'status' => 'late',
            'source' => 'clock_device',
            'hours_worked' => 7.5,
            'expected_hours' => 8.0,
            'late_minutes' => 30,
            'lunch_late_minutes' => 0,
            'lateness_waived' => false,
        ]);

        $period = PayPeriod::query()->create([
            'organization_id' => $org->id,
            'period_code' => 'SEP'.uniqid(),
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
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
        $this->assertSame(30, (int) $line['payroll_meta']['late_minutes_total']);
        $this->assertGreaterThan(0.0, (float) $line['payroll_meta']['lateness_amount']);
    }

    public function test_payroll_skips_residual_cash_advance_and_labels_open_advances_with_date(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $employee = Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'employee_code' => 'EMP#ADV'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#ADV'.strtoupper(uniqid()),
            'first_name' => 'Advance',
            'last_name' => 'Carry',
            'full_name' => 'Advance Carry',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 20000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        EmployeeCashAdvance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'advance_date' => '2026-08-15',
            'amount' => 3500,
            'balance' => 0.01,
            'status' => 'open',
            'repayment_mode' => 'full_next_cycle',
            'notes' => 'personal',
        ]);

        EmployeeCashAdvance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'advance_date' => '2026-08-20',
            'amount' => 3000,
            'balance' => 3000,
            'status' => 'open',
            'repayment_mode' => 'full_next_cycle',
            'notes' => null,
        ]);

        EmployeeCashAdvance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'advance_date' => '2026-10-01',
            'amount' => 500,
            'balance' => 500,
            'status' => 'open',
            'repayment_mode' => 'full_next_cycle',
        ]);

        $built = app(PayrollOtherDeductionsBuilder::class)->build(
            $employee,
            20000.0,
            [
                'enable_cash_advance_deductions' => true,
                'deduct_cash_advances_on_payroll' => true,
                'include_other_deductions_in_payroll' => false,
            ],
            '2026-09-30',
        );

        $this->assertSame(3000.0, $built['total']);
        $this->assertCount(1, $built['detail']);
        $this->assertStringContainsString('20 Aug 2026', $built['detail'][0]['name']);
    }

    public function test_cash_advances_still_build_when_other_deductions_setting_off(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $employee = Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'employee_code' => 'EMP#ADV2'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#ADV2'.strtoupper(uniqid()),
            'first_name' => 'Full',
            'last_name' => 'Cycle',
            'full_name' => 'Full Cycle',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 40000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        EmployeeCashAdvance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'advance_date' => '2026-08-10',
            'amount' => 2176,
            'balance' => 2176,
            'status' => 'open',
            'repayment_mode' => 'full_next_cycle',
            'notes' => 'PERSONAL',
        ]);

        $built = app(PayrollOtherDeductionsBuilder::class)->build(
            $employee,
            40000.0,
            [
                'enable_cash_advance_deductions' => true,
                'deduct_cash_advances_on_payroll' => true,
                'include_other_deductions_in_payroll' => false,
            ],
            '2026-08-31',
        );

        $this->assertSame(2176.0, $built['total']);
        $this->assertCount(1, $built['detail']);
        $this->assertSame('cash_advance', $built['detail'][0]['type']);
        $this->assertSame(2176.0, $built['detail'][0]['amount']);
        $this->assertSame('full_next_cycle', $built['detail'][0]['repayment_mode']);
    }
}
