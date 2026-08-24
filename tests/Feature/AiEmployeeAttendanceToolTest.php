<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\Tools\GetEmployeeAttendanceTool;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiEmployeeAttendanceToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_attendance_lookup_returns_named_employee_without_ids(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00'));

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $org->id)->firstOrFail();

        $employee = Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $template->shift_id,
            'employee_code' => 'EMP#AI'.strtoupper(uniqid()),
            'payroll_number' => 'EMP#AI'.strtoupper(uniqid()),
            'first_name' => 'Ada',
            'last_name' => 'Attendance',
            'full_name' => 'Ada Attendance',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2026-01-01',
            'base_salary' => 50000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        EmployeeAttendance::query()->create([
            'organization_id' => $org->id,
            'employee_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'attendance_date' => '2026-08-24',
            'status' => 'present',
            'source' => 'manual',
            'hours_worked' => 8.5,
            'late_minutes' => 0,
            'check_in' => '08:05:00',
            'check_out' => '17:00:00',
        ]);

        Sanctum::actingAs($admin);

        /** @var GetEmployeeAttendanceTool $tool */
        $tool = app(GetEmployeeAttendanceTool::class);
        $result = $tool->execute($admin, [
            'employee_name' => 'Ada Attendance',
            'relative_date' => 'today',
        ]);

        $this->assertSame('2026-08-24', $result['from_date']);
        $this->assertSame('Ada Attendance', $result['employee']['name'] ?? null);
        $this->assertSame($employee->employee_code, $result['employee']['employee_code'] ?? null);
        $this->assertArrayNotHasKey('employee_id', $result['employee']);
        $this->assertArrayNotHasKey('id', $result['employee']);
        $this->assertCount(1, $result['days']);
        $this->assertSame('08:05', $result['days'][0]['check_in']);
        $this->assertSame('present', $result['days'][0]['status']);
        $this->assertTrue(
            collect($result['screens'])->contains(fn ($row) => ($row['path'] ?? null) === '/hr/attendance'),
        );

        Carbon::setTestNow();
    }
}
