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

    public function test_waived_lateness_does_not_create_fractional_absent_days(): void
    {
        $this->travelTo('2026-09-30 18:00:00');

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

        // One weekday with late minutes waived — hours still short as if late was not fully restored.
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

        // Issued after September period — must not deduct on September payroll.
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
        $this->assertSame('cash_advance', $built['detail'][0]['type']);
        $this->assertStringContainsString('20 Aug 2026', $built['detail'][0]['name']);
        $this->assertSame('2026-08-20', $built['detail'][0]['advance_date']);
    }

    public function test_proration_does_not_double_count_lateness_in_period_basic(): void
    {
        $this->travelTo('2026-09-02 18:00:00');

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'DBL'.strtoupper(substr(uniqid(), -6)),
            'shift_name' => 'Double Late',
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
            'employee_code' => 'EMP#DBL'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#DBL'.strtoupper(uniqid()),
            'first_name' => 'Late',
            'last_name' => 'Once',
            'full_name' => 'Late Once',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 30000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        // Tuesday 1 Sep — half day of hours, 30 min late not waived.
        EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => '2026-09-01',
            'status' => 'late',
            'source' => 'clock_device',
            'hours_worked' => 4.0,
            'expected_hours' => 8.0,
            'late_minutes' => 30,
            'lunch_late_minutes' => 0,
            'lateness_waived' => false,
        ]);

        $period = PayPeriod::query()->create([
            'organization_id' => $org->id,
            'period_code' => 'DBL'.uniqid(),
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
        $absent = (float) $line['payroll_meta']['absent_days'];
        $paid = (float) $line['payroll_meta']['paid_work_days'];
        $this->assertGreaterThan(0.0, $absent);
        $this->assertEqualsWithDelta(1.0, $absent + $paid, 0.02);
        // Hour shortfall already reduces paid days — lateness must not add a second hold.
        $this->assertEquals(0.0, (float) $line['payroll_meta']['lateness_amount']);
        $this->assertGreaterThan(0, (int) $line['payroll_meta']['late_minutes_total']);

        $contract = 30000.0;
        $expectedDays = (float) $line['payroll_meta']['expected_work_days'];
        if ($expectedDays >= 29.5) {
            // fixed_30 basis
            $this->assertEquals(
                round($contract * (30 - $absent) / 30, 2),
                (float) $line['payroll_meta']['period_basic'],
            );
        } else {
            $this->assertEquals(
                round($contract * ($paid / max(1.0, $expectedDays)), 2),
                (float) $line['payroll_meta']['period_basic'],
            );
        }
    }
}
