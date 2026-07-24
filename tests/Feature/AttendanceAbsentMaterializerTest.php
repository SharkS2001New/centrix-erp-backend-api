<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeaveDay;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Attendance\AttendanceAbsentMaterializer;
use Carbon\Carbon;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AttendanceAbsentMaterializerTest extends TestCase
{
    use RefreshesErpDatabase;

    protected Organization $org;

    protected User $admin;

    protected WorkShift $shift;

    protected Employee $employee;

    protected string $workDate = '2026-07-20'; // Monday

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-21 10:00:00')); // Tuesday — yesterday is Monday

        $this->org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $this->org->id)->firstOrFail();

        $this->shift = WorkShift::query()->create([
            'organization_id' => $this->org->id,
            'shift_code' => 'ABS'.uniqid(),
            'shift_name' => 'Absent materializer shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'works_saturday' => false,
            'works_sunday' => false,
            'works_public_holidays' => false,
            'is_active' => true,
        ]);

        $this->employee = Employee::query()->create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $this->shift->id,
            'employee_code' => 'EMP#A'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#A'.strtoupper(uniqid()),
            'first_name' => 'Absent',
            'last_name' => 'Tester',
            'full_name' => 'Absent Tester',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 40000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_marks_scheduled_day_without_attendance_as_absent(): void
    {
        $result = app(AttendanceAbsentMaterializer::class)->markDate($this->org->id, $this->workDate);

        $this->assertGreaterThanOrEqual(1, $result['created_count']);

        $row = EmployeeAttendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('attendance_date', $this->workDate)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('absent', $row->status);
        $this->assertEquals(0, (float) $row->hours_worked);
        $this->assertStringContainsString('Auto-marked absent', (string) $row->notes);
    }

    public function test_does_not_overwrite_existing_attendance(): void
    {
        EmployeeAttendance::query()->create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'branch_id' => $this->employee->branch_id,
            'attendance_date' => $this->workDate,
            'status' => 'present',
            'source' => 'manual',
            'hours_worked' => 9,
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
        ]);

        $result = app(AttendanceAbsentMaterializer::class)->markDate($this->org->id, $this->workDate);

        $row = EmployeeAttendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('attendance_date', $this->workDate)
            ->first();

        $this->assertSame('present', $row->status);
        $this->assertEquals(9.0, (float) $row->hours_worked);
        $this->assertSame(0, collect($result['created'])->where('employee_id', $this->employee->id)->count());
    }

    public function test_skips_leave_days(): void
    {
        EmployeeLeaveDay::query()->create([
            'organization_id' => $this->org->id,
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'assignment_kind' => 'leave',
            'start_date' => $this->workDate,
            'end_date' => $this->workDate,
            'duration_type' => 'full_day',
            'approval_status' => 'approved',
            'days_count' => 1,
        ]);

        $result = app(AttendanceAbsentMaterializer::class)->markDate($this->org->id, $this->workDate);

        $this->assertSame(
            0,
            EmployeeAttendance::query()
                ->where('employee_id', $this->employee->id)
                ->whereDate('attendance_date', $this->workDate)
                ->count(),
        );
        $this->assertSame(0, collect($result['created'])->where('employee_id', $this->employee->id)->count());
    }

    public function test_does_not_mark_today(): void
    {
        $today = Carbon::today()->toDateString();
        $result = app(AttendanceAbsentMaterializer::class)->markDate($this->org->id, $today);

        $this->assertSame(0, $result['created_count']);
    }
}
