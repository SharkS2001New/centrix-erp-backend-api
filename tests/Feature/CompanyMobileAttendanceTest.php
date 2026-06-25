<?php

namespace Tests\Feature;

use App\Models\AttendanceBranchPremises;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CompanyMobileAttendanceTest extends TestCase
{
    use RefreshesErpDatabase;

    /** @return array{0: Organization, 1: User} */
    protected function enableCompanyMobileAttendance(): array
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('organization_id', $org->id)->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['hr_payroll']['attendance_capture_mode'] = 'company_mobile';
        $settings['hr_payroll']['company_premises_latitude'] = -1.2921;
        $settings['hr_payroll']['company_premises_longitude'] = 36.8219;
        $org->update(['module_settings' => $settings]);

        AttendanceBranchPremises::query()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'branch_id' => $admin->branch_id,
            ],
            [
                'latitude' => -1.2921,
                'longitude' => 36.8219,
                'radius_metres' => 5,
                'updated_by' => $admin->id,
            ],
        );

        return [$org, $admin];
    }

    public function test_device_status_requires_registration_for_attendance_phone(): void
    {
        $this->enableCompanyMobileAttendance();

        $this->getJson('/api/v1/company-mobile-attendance/device-status?company_code=DEMO&device_identifier=android:test-device')
            ->assertOk()
            ->assertJsonPath('feature_enabled', true)
            ->assertJsonPath('device_registered', false)
            ->assertJsonPath('attendance_phone', false);
    }

    public function test_device_status_matches_registration_without_platform_prefix(): void
    {
        [, $admin] = $this->enableCompanyMobileAttendance();

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/attendance-mobile-devices', [
                'device_identifier' => 'ap3a.240905.015.a2',
                'branch_id' => $admin->branch_id,
                'platform' => 'android',
            ])
            ->assertCreated();

        $this->getJson('/api/v1/company-mobile-attendance/device-status?company_code=DEMO&device_identifier=android:ap3a.240905.015.a2')
            ->assertOk()
            ->assertJsonPath('device_registered', true);
    }

    public function test_employee_search_requires_three_characters(): void
    {
        [, $admin] = $this->enableCompanyMobileAttendance();

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/attendance-mobile-devices', [
            'device_identifier' => 'search-test-device',
            'branch_id' => $admin->branch_id,
            'platform' => 'android',
        ])->assertCreated();

        $this->getJson('/api/v1/company-mobile-attendance/employees?company_code=DEMO&device_identifier=search-test-device&q=ab')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter at least 3 characters to search employees.');
    }

    public function test_employee_search_finds_active_employee_case_insensitively(): void
    {
        [$org, $admin] = $this->enableCompanyMobileAttendance();

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/attendance-mobile-devices', [
            'device_identifier' => 'search-test-device',
            'branch_id' => $admin->branch_id,
            'platform' => 'android',
        ])->assertCreated();

        $departmentId = Employee::query()->where('organization_id', $org->id)->value('department_id');
        $positionId = Employee::query()->where('organization_id', $org->id)->value('position_id');

        Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'employee_code' => 'EMP#STEPHEN',
            'payroll_number' => 'EMP#STEPHEN',
            'first_name' => 'Stephen',
            'last_name' => 'Kariuki',
            'full_name' => 'Stephen Kariuki',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => now()->toDateString(),
            'base_salary' => 45000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/company-mobile-attendance/employees?company_code=DEMO&device_identifier=search-test-device&q=stephen')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Stephen Kariuki');
    }

    public function test_employee_search_matches_name_prefix_when_query_is_misspelled(): void
    {
        [$org, $admin] = $this->enableCompanyMobileAttendance();

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/attendance-mobile-devices', [
            'device_identifier' => 'search-test-device',
            'branch_id' => $admin->branch_id,
            'platform' => 'android',
        ])->assertCreated();

        $departmentId = Employee::query()->where('organization_id', $org->id)->value('department_id');
        $positionId = Employee::query()->where('organization_id', $org->id)->value('position_id');

        Employee::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'employee_code' => 'EMP#STEPHEN',
            'payroll_number' => 'EMP#STEPHEN',
            'first_name' => 'Stephen',
            'last_name' => 'Kariuki',
            'full_name' => 'Stephen Kariuki',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => now()->toDateString(),
            'base_salary' => 45000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/company-mobile-attendance/employees?company_code=DEMO&device_identifier=search-test-device&q=karuku')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Stephen Kariuki');
    }
}
