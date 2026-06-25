<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CompanyMobileAttendanceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_device_status_requires_registration_for_attendance_phone(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['hr_payroll']['attendance_capture_mode'] = 'company_mobile';
        $settings['hr_payroll']['company_premises_latitude'] = -1.2921;
        $settings['hr_payroll']['company_premises_longitude'] = 36.8219;
        $org->update(['module_settings' => $settings]);

        $this->getJson('/api/v1/company-mobile-attendance/device-status?company_code=DEMO&device_identifier=android:test-device')
            ->assertOk()
            ->assertJsonPath('feature_enabled', true)
            ->assertJsonPath('device_registered', false)
            ->assertJsonPath('attendance_phone', false);
    }

    public function test_device_status_matches_registration_without_platform_prefix(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('organization_id', $org->id)->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['hr_payroll']['attendance_capture_mode'] = 'company_mobile';
        $settings['hr_payroll']['company_premises_latitude'] = -1.2921;
        $settings['hr_payroll']['company_premises_longitude'] = 36.8219;
        $org->update(['module_settings' => $settings]);

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
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('organization_id', $org->id)->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['hr_payroll']['attendance_capture_mode'] = 'company_mobile';
        $settings['hr_payroll']['company_premises_latitude'] = -1.2921;
        $settings['hr_payroll']['company_premises_longitude'] = 36.8219;
        $org->update(['module_settings' => $settings]);

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
}
