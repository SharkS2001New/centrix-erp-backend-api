<?php

namespace Tests\Unit\Attendance;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\InAppNotification;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Attendance\AttendanceAnomalyAlertService;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AttendanceAnomalyAlertServiceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_detects_inverted_and_tiny_sessions(): void
    {
        $attendance = new EmployeeAttendance([
            'status' => 'late',
            'check_in' => '19:55:00',
            'check_out' => '18:56:00',
            'hours_worked' => 0,
            'late_minutes' => 700,
        ]);

        $flags = app(AttendanceAnomalyAlertService::class)->detectFlags([
            [
                'in' => Carbon::parse('2026-08-11 19:55:00', AppTimezone::name()),
                'out' => Carbon::parse('2026-08-11 18:56:00', AppTimezone::name()),
            ],
        ], $attendance);

        $this->assertContains('clock_out_before_clock_in', $flags);
        $this->assertContains('implausibly_short_work', $flags);
    }

    public function test_notifies_hr_with_heuristic_message_when_ai_unavailable(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $shift = WorkShift::query()->create([
            'organization_id' => $org->id,
            'shift_code' => 'AN'.uniqid(),
            'shift_name' => 'Anomaly shift',
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
            'employee_code' => 'AN'.uniqid(),
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

        $attendance = EmployeeAttendance::query()->create([
            'organization_id' => $org->id,
            'employee_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'attendance_date' => '2026-08-11',
            'check_in' => '19:55:00',
            'check_out' => '18:56:00',
            'status' => 'late',
            'source' => 'clock_device',
            'hours_worked' => 0,
            'expected_hours' => 8,
            'late_minutes' => 700,
            'lunch_late_minutes' => 0,
            'lunch_status' => '-',
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
        ]);

        app(AttendanceAnomalyAlertService::class)->evaluateAfterReconcile(
            $employee,
            '2026-08-11',
            [
                [
                    'in' => Carbon::parse('2026-08-11 19:55:00', AppTimezone::name()),
                    'out' => Carbon::parse('2026-08-11 18:56:00', AppTimezone::name()),
                ],
            ],
            $attendance,
        );

        $this->assertTrue(
            InAppNotification::query()
                ->where('organization_id', $org->id)
                ->where('type', AttendanceAnomalyAlertService::TYPE)
                ->where('user_id', $admin->id)
                ->where('title', 'Attendance needs review: Naomi Maina')
                ->exists()
        );
    }
}
