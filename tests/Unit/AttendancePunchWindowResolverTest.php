<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\WorkShift;
use App\Services\Attendance\AttendancePunchWindowResolver;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AttendancePunchWindowResolverTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_standard_shift_places_lunch_at_midpoint(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'WIN'.uniqid(),
            'shift_name' => 'Window shift',
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
            'employee_code' => 'WIN'.uniqid(),
            'first_name' => 'Win',
            'last_name' => 'Dow',
            'full_name' => 'Win Dow',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2024-01-01',
            'base_salary' => 1,
            'country' => 'Kenya',
            'is_active' => true,
        ]);
        $employee->setRelation('shift', $shift);

        $windows = app(AttendancePunchWindowResolver::class)->windowsFor(
            $employee,
            Carbon::parse('2026-08-11 12:00:00', AppTimezone::name()),
        );

        $this->assertSame('shift', $windows['source']);
        $this->assertSame('07:00', $windows['morning_clock_in_from']);
        $this->assertSame('10:00', $windows['morning_clock_in_to']);
        $this->assertSame('11:30', $windows['lunch_clock_out_from']);
        $this->assertSame('13:00', $windows['lunch_clock_out_to']);
        $this->assertSame('12:00', $windows['lunch_clock_in_from']);
        $this->assertSame('15:00', $windows['lunch_clock_in_to']);
        $this->assertSame('16:00', $windows['evening_clock_out_from']);
        $this->assertSame('22:00', $windows['evening_clock_out_to']);
        $this->assertSame('08:15', $windows['clock_in_late_after']);
    }

    public function test_later_shift_moves_lunch_and_late_threshold(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'EVE'.uniqid(),
            'shift_name' => 'Ten to seven',
            'start_time' => '10:00:00',
            'end_time' => '19:00:00',
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
            'employee_code' => 'EVE'.uniqid(),
            'first_name' => 'Eve',
            'last_name' => 'Ning',
            'full_name' => 'Eve Ning',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2024-01-01',
            'base_salary' => 1,
            'country' => 'Kenya',
            'is_active' => true,
        ]);
        $employee->setRelation('shift', $shift);

        $windows = app(AttendancePunchWindowResolver::class)->windowsFor(
            $employee,
            Carbon::parse('2026-08-11 12:00:00', AppTimezone::name()),
        );

        $this->assertSame('shift', $windows['source']);
        $this->assertSame('13:30', $windows['lunch_clock_out_from']);
        $this->assertSame('15:00', $windows['lunch_clock_out_to']);
        $this->assertSame('18:00', $windows['evening_clock_out_from']);
        $this->assertSame('00:00', $windows['evening_clock_out_to']);
        $this->assertSame('10:15', $windows['clock_in_late_after']);
    }

    public function test_saturday_half_day_uses_shift_end_clock_out_window(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'SAT'.uniqid(),
            'shift_name' => 'Weekday full Saturday half',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'works_saturday' => true,
            'use_alternate_hours' => true,
            'alternate_start_time' => '08:00:00',
            'alternate_end_time' => '12:00:00',
            'is_active' => true,
        ]);
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();
        $employee = Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $template->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $shift->id,
            'employee_code' => 'SAT'.uniqid(),
            'first_name' => 'Sat',
            'last_name' => 'Urday',
            'full_name' => 'Sat Urday',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2024-01-01',
            'base_salary' => 1,
            'country' => 'Kenya',
            'is_active' => true,
        ]);
        $employee->setRelation('shift', $shift);

        $windows = app(AttendancePunchWindowResolver::class)->windowsFor(
            $employee,
            Carbon::parse('2026-08-15 12:00:00', AppTimezone::name()),
        );

        $this->assertSame('shift', $windows['source']);
        $this->assertSame('', $windows['lunch_clock_out_from']);
        $this->assertSame('', $windows['lunch_clock_out_to']);
        $this->assertSame('11:00', $windows['evening_clock_out_from']);
        $this->assertSame('17:00', $windows['evening_clock_out_to']);

        $weekday = app(AttendancePunchWindowResolver::class)->windowsFor(
            $employee,
            Carbon::parse('2026-08-14 12:00:00', AppTimezone::name()),
        );
        $this->assertSame('11:30', $weekday['lunch_clock_out_from']);
        $this->assertSame('16:00', $weekday['evening_clock_out_from']);
    }
}
