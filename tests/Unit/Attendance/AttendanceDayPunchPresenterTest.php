<?php

namespace Tests\Unit\Attendance;

use App\Models\Employee;
use App\Models\EmployeeClockSession;
use App\Models\Organization;
use App\Models\WorkShift;
use App\Services\Attendance\AttendanceDayPunchPresenter;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AttendanceDayPunchPresenterTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_afternoon_only_arrival_maps_to_lunch_in_not_clock_in(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'PR'.uniqid(),
            'shift_name' => 'Presenter shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'works_saturday' => true,
            'works_sunday' => true,
            'works_public_holidays' => true,
            'is_active' => true,
        ]);
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();
        $employee = Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $template->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $shift->id,
            'employee_code' => 'PR'.uniqid(),
            'first_name' => 'Naomi',
            'last_name' => 'Maina',
            'full_name' => 'Naomi Maina',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2024-01-01',
            'base_salary' => 1,
            'country' => 'Kenya',
            'is_active' => true,
        ]);
        $employee->setRelation('shift', $shift);

        $session = EmployeeClockSession::query()->create([
            'organization_id' => $org->id,
            'employee_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'clock_in_at' => '2026-08-11 13:05:00',
            'clock_out_at' => '2026-08-11 17:30:00',
            'source' => 'clock_device',
            'needs_reconciliation' => false,
        ]);

        $presented = app(AttendanceDayPunchPresenter::class)->present(
            $employee,
            '2026-08-11',
            [$session],
        );

        $this->assertNull($presented['clock_in']);
        $this->assertSame('13:05', $presented['lunch_in']);
        $this->assertSame('17:30', $presented['clock_out']);
    }

    public function test_clock_out_before_clock_in_is_cleared_from_presentation(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'ABS'.uniqid(),
            'shift_name' => 'Absurd presenter',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'works_saturday' => true,
            'works_sunday' => true,
            'works_public_holidays' => true,
            'is_active' => true,
        ]);
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();
        $employee = Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $template->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $shift->id,
            'employee_code' => 'ABS'.uniqid(),
            'first_name' => 'Abs',
            'last_name' => 'Urd',
            'full_name' => 'Abs Urd',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2024-01-01',
            'base_salary' => 1,
            'country' => 'Kenya',
            'is_active' => true,
        ]);
        $employee->setRelation('shift', $shift);

        $session = EmployeeClockSession::query()->create([
            'organization_id' => $org->id,
            'employee_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'clock_in_at' => '2026-08-11 19:55:00',
            'clock_out_at' => '2026-08-11 18:56:00',
            'source' => 'clock_device',
            'needs_reconciliation' => false,
        ]);

        $presented = app(AttendanceDayPunchPresenter::class)->present(
            $employee,
            '2026-08-11',
            [$session],
        );

        $this->assertSame('19:55', $presented['clock_in']);
        $this->assertNull($presented['clock_out']);
    }
}
