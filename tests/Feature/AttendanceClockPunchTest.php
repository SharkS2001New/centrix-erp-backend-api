<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\AttendanceClockDevice;
use App\Models\Employee;
use App\Models\EmployeeClockSession;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkShift;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AttendanceClockPunchTest extends TestCase
{
    use RefreshesErpDatabase;

    protected Organization $org;

    protected User $admin;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
        $this->org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        $template = Employee::query()->where('organization_id', $this->org->id)->firstOrFail();

        $shift = WorkShift::query()->create([
            'organization_id' => $this->org->id,
            'shift_code' => 'CLK'.uniqid(),
            'shift_name' => 'Clock punch shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'works_saturday' => true,
            'works_sunday' => true,
            'works_public_holidays' => true,
            'is_active' => true,
        ]);

        $this->employee = Employee::query()->create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP#HIK001',
            'payroll_number' => 'EMP#HIK001',
            'first_name' => 'Hik',
            'last_name' => 'Vision',
            'full_name' => 'Hik Vision',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2024-01-01',
            'base_salary' => 50000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        AttendanceClockDevice::query()->create([
            'organization_id' => $this->org->id,
            'device_no' => 'TERMINAL-01',
            'location' => 'Reception',
            'is_active' => true,
            'provider' => 'hikvision',
        ]);
    }

    public function test_clock_punch_auto_toggles_in_then_out_by_employee_code(): void
    {
        Sanctum::actingAs($this->admin);

        $in = $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T08:05:00+03:00',
            'direction' => 'auto',
        ]);
        $in->assertCreated()->assertJsonPath('action', 'in');

        $this->assertDatabaseHas('employee_clock_sessions', [
            'employee_id' => $this->employee->id,
            'device_identifier' => 'TERMINAL-01',
        ]);
        $this->assertNull(EmployeeClockSession::query()->where('employee_id', $this->employee->id)->value('clock_out_at'));
        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-11',
            'source' => 'clock_device',
            'check_in' => '08:05:00',
        ]);

        $out = $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T17:02:00+03:00',
            'direction' => 'auto',
        ]);
        $out->assertOk()->assertJsonPath('action', 'out');
        $this->assertNotNull(
            EmployeeClockSession::query()->where('employee_id', $this->employee->id)->value('clock_out_at')
        );
        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-11',
            'check_out' => '17:02:00',
        ]);
    }

    public function test_clock_punch_rejects_unregistered_device(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'UNKNOWN-99',
            'direction' => 'in',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_no']);
    }

    public function test_register_hikvision_device_with_host(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/v1/attendance-clock-devices', [
            'device_no' => 'TERMINAL-02',
            'location' => 'Gate',
            'provider' => 'hikvision',
            'host' => '192.168.1.50',
            'port' => 80,
            'username' => 'admin',
            'password' => 'Secret123!',
            'is_active' => true,
        ]);

        $res->assertCreated()
            ->assertJsonPath('device_no', 'TERMINAL-02')
            ->assertJsonPath('host', '192.168.1.50')
            ->assertJsonPath('has_password', true)
            ->assertJsonMissingPath('password_encrypted');

        $deviceId = (int) $res->json('id');
        $this->getJson("/api/v1/attendance-clock-devices/{$deviceId}")
            ->assertOk()
            ->assertJsonPath('id', $deviceId)
            ->assertJsonPath('device_no', 'TERMINAL-02');

        $this->getJson('/api/v1/attendance-clock-devices/undefined')
            ->assertNotFound();
    }

    public function test_super_admin_can_show_tenant_clock_device_via_platform_proxy(): void
    {
        $superAdmin = User::query()->where('is_super_admin', true)->where('is_active', true)->firstOrFail();

        Sanctum::actingAs($superAdmin);

        $device = AttendanceClockDevice::query()->create([
            'organization_id' => $this->org->id,
            'device_no' => 'MOON-DEVICE',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.1.60',
        ]);

        $this->getJson("/api/v1/admin/organizations/{$this->org->id}/attendance-clock-devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('id', $device->id)
            ->assertJsonPath('device_no', 'MOON-DEVICE');
    }

    public function test_issue_agent_package_returns_prefilled_config_and_token(): void
    {
        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::query()->create([
            'organization_id' => $this->org->id,
            'device_no' => 'TERMINAL-AGENT',
            'location' => 'Gate',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '10.0.0.20',
            'port' => 80,
            'username' => 'admin',
            'use_https' => false,
        ]);
        $device->setPlainPassword('DevicePass!');
        $device->save();

        $res = $this->postJson("/api/v1/attendance-clock-devices/{$device->id}/agent-package", [
            'centrix_api_url' => 'https://example.test/api/v1',
        ]);

        $res->assertOk()
            ->assertJsonPath('config.deviceNo', 'TERMINAL-AGENT')
            ->assertJsonPath('config.deviceId', $device->id)
            ->assertJsonPath('config.centrixApiUrl', 'https://example.test/api/v1')
            ->assertJsonPath('config.hikvision.host', '10.0.0.20')
            ->assertJsonPath('config.hikvision.password', 'DevicePass!')
            ->assertJsonPath('needs_device_ip', false)
            ->assertJsonPath('needs_device_password', false);

        $token = $res->json('config.centrixToken');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/attendance/clock-punch', [
                'employee_code' => 'EMP#HIK001',
                'device_no' => 'TERMINAL-AGENT',
                'punched_at' => '2026-08-11T09:00:00+03:00',
                'direction' => 'in',
            ])
            ->assertCreated()
            ->assertJsonPath('action', 'in');
    }
}
