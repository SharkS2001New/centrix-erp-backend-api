<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\AttendanceClockDevice;
use App\Models\Employee;
use App\Models\EmployeeClockSession;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionEmployeeMapping;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
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

    public function test_morning_extra_punches_are_ignored_and_lunch_windows_toggle(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T08:10:00+03:00',
            'direction' => 'auto',
        ])->assertCreated()->assertJsonPath('action', 'in');

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T08:40:00+03:00',
            'direction' => 'auto',
        ])->assertOk()->assertJsonPath('action', 'ignored');

        $this->assertSame(1, EmployeeClockSession::query()->where('employee_id', $this->employee->id)->count());
        $this->assertNull(EmployeeClockSession::query()->where('employee_id', $this->employee->id)->value('clock_out_at'));

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T12:45:00+03:00',
            'direction' => 'auto',
        ])->assertOk()->assertJsonPath('action', 'out');

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T12:55:00+03:00',
            'direction' => 'auto',
        ])->assertOk()->assertJsonPath('action', 'ignored');

        $this->assertSame(1, EmployeeClockSession::query()->where('employee_id', $this->employee->id)->count());

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T14:10:00+03:00',
            'direction' => 'auto',
        ])->assertCreated()->assertJsonPath('action', 'in');

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T17:30:00+03:00',
            'direction' => 'auto',
        ])->assertOk()->assertJsonPath('action', 'out');

        $this->assertSame(2, EmployeeClockSession::query()->where('employee_id', $this->employee->id)->count());
        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-11',
            'check_in' => '08:10:00',
            'check_out' => '17:30:00',
        ]);
    }

    public function test_first_punch_of_the_day_is_always_clock_in(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T15:05:00+03:00',
            'direction' => 'auto',
        ])->assertCreated()->assertJsonPath('action', 'in');

        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-11',
            'check_in' => '15:05:00',
        ]);
    }

    public function test_nairobi_offset_is_stored_as_nairobi_clock_time(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T12:00:00+03:00',
            'direction' => 'in',
        ])->assertCreated();

        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-11',
            'check_in' => '12:00:00',
        ]);
    }

    public function test_hikvision_china_offset_keeps_device_wall_clock(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T10:26:00+08:00',
            'direction' => 'in',
        ])->assertCreated();

        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-11',
            'check_in' => '10:26:00',
        ]);
    }

    public function test_hr_can_delete_a_clock_session_and_clears_the_day(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T08:05:00+03:00',
            'direction' => 'in',
        ])->assertCreated();

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T17:02:00+03:00',
            'direction' => 'out',
        ])->assertOk();

        $sessionId = EmployeeClockSession::query()->where('employee_id', $this->employee->id)->value('id');
        $this->assertNotNull($sessionId);

        $this->deleteJson('/api/v1/attendance/clock-sessions/'.$sessionId)->assertNoContent();

        $this->assertDatabaseMissing('employee_clock_sessions', [
            'id' => $sessionId,
        ]);
        $this->assertDatabaseMissing('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-11',
        ]);
    }

    public function test_deleting_open_session_rebuilds_closed_punches(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T08:05:00+03:00',
            'direction' => 'in',
        ])->assertCreated();
        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T13:30:00+03:00',
            'direction' => 'out',
        ])->assertOk();
        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T14:05:00+03:00',
            'direction' => 'in',
        ])->assertCreated();

        $openId = EmployeeClockSession::query()
            ->where('employee_id', $this->employee->id)
            ->whereNull('clock_out_at')
            ->value('id');
        $this->assertNotNull($openId);

        $this->deleteJson('/api/v1/attendance/clock-sessions/'.$openId)->assertNoContent();

        $this->assertSame(1, EmployeeClockSession::query()->where('employee_id', $this->employee->id)->count());
        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-11',
            'check_in' => '08:05:00',
            'check_out' => '13:30:00',
        ]);
    }

    public function test_auto_punches_follow_employee_shift_hours(): void
    {
        Sanctum::actingAs($this->admin);

        WorkShift::query()->whereKey($this->employee->shift_id)->update([
            'start_time' => '10:00:00',
            'end_time' => '19:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
        ]);
        $this->employee->unsetRelation('shift');

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T10:10:00+03:00',
            'direction' => 'auto',
        ])->assertCreated()->assertJsonPath('action', 'in');

        // Org default lunch-out is 12:30–14:00; this shift lunches around 14:00.
        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T12:45:00+03:00',
            'direction' => 'auto',
        ])->assertOk()->assertJsonPath('action', 'missed');

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T14:10:00+03:00',
            'direction' => 'auto',
        ])->assertOk()->assertJsonPath('action', 'out');

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T15:20:00+03:00',
            'direction' => 'auto',
        ])->assertCreated()->assertJsonPath('action', 'in');

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T19:15:00+03:00',
            'direction' => 'auto',
        ])->assertOk()->assertJsonPath('action', 'out');

        $this->assertSame(2, EmployeeClockSession::query()->where('employee_id', $this->employee->id)->count());
        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-11',
            'check_in' => '10:10:00',
            'check_out' => '19:15:00',
        ]);
    }

    public function test_lateness_grace_follows_shift_start(): void
    {
        Sanctum::actingAs($this->admin);

        WorkShift::query()->whereKey($this->employee->shift_id)->update([
            'start_time' => '10:00:00',
            'end_time' => '19:00:00',
        ]);
        $this->employee->unsetRelation('shift');

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-12T10:10:00+03:00',
            'direction' => 'in',
        ])->assertCreated();

        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-12',
            'check_in' => '10:10:00',
            'late_minutes' => 0,
            'status' => 'present',
        ]);
    }

    public function test_same_hour_zulu_punch_is_not_recorded_as_end_of_day(): void
    {
        Sanctum::actingAs($this->admin);
        Carbon::setTestNow(Carbon::parse('2026-08-14 18:00:00', 'Africa/Nairobi'));

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-14T08:10:00Z',
            'direction' => 'auto',
        ])->assertCreated()->assertJsonPath('action', 'in');

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-14T08:35:00Z',
            'direction' => 'auto',
        ])->assertOk()->assertJsonPath('action', 'ignored');

        $session = EmployeeClockSession::query()->where('employee_id', $this->employee->id)->first();
        $this->assertNotNull($session);
        $this->assertNull($session->clock_out_at);
        $this->assertSame('08:10:00', $session->clock_in_at?->timezone('Africa/Nairobi')->format('H:i:s'));

        Carbon::setTestNow();
    }

    public function test_next_day_morning_punch_does_not_close_yesterday_at_2359(): void
    {
        Sanctum::actingAs($this->admin);
        Carbon::setTestNow(Carbon::parse('2026-08-14 18:00:00', 'Africa/Nairobi'));

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-13T08:10:00+03:00',
            'direction' => 'auto',
        ])->assertCreated()->assertJsonPath('action', 'in');

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-14T08:25:00+03:00',
            'direction' => 'auto',
        ])->assertCreated()->assertJsonPath('action', 'in');

        $yesterday = EmployeeClockSession::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('clock_in_at', '2026-08-13')
            ->first();
        $this->assertNotNull($yesterday);
        $this->assertNull($yesterday->clock_out_at);

        $today = EmployeeClockSession::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('clock_in_at', '2026-08-14')
            ->first();
        $this->assertNotNull($today);
        $this->assertSame('08:25:00', $today->clock_in_at?->timezone('Africa/Nairobi')->format('H:i:s'));
        $this->assertNull($today->clock_out_at);

        $this->assertDatabaseMissing('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-13',
            'check_out' => '23:59:59',
        ]);

        Carbon::setTestNow();
    }

    public function test_forgotten_evening_clock_out_is_closed_at_shift_end(): void
    {
        Sanctum::actingAs($this->admin);
        Carbon::setTestNow(Carbon::parse('2026-08-14 02:05:00', 'Africa/Nairobi'));

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-13T08:10:00+03:00',
            'direction' => 'auto',
        ])->assertCreated();

        $result = app(\App\Services\Attendance\ForgottenClockOutService::class)->closeDueSessions((int) $this->org->id);
        $this->assertSame(1, $result['closed']);

        $session = EmployeeClockSession::query()->where('employee_id', $this->employee->id)->first();
        $this->assertNotNull($session->clock_out_at);
        $this->assertSame('17:00:00', $session->clock_out_at->timezone('Africa/Nairobi')->format('H:i:s'));
        $this->assertTrue((bool) $session->needs_reconciliation);
        $this->assertSame(EmployeeClockSession::CLOCK_OUT_KIND_AUTO_FORGOTTEN, $session->clock_out_kind);

        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-13',
            'check_in' => '08:10:00',
            'check_out' => '17:00:00',
        ]);

        $list = $this->getJson('/api/v1/attendance/missed-punches')->assertOk();
        $this->assertSame(1, $list->json('counts.missing_clock_out'));
        $this->assertTrue($list->json('missing_clock_out.0.auto_closed'));

        $this->postJson('/api/v1/attendance/missed-punches/'.$session->id.'/clock-out', [
            'confirm_reconciliation' => true,
        ])->assertOk();

        $session->refresh();
        $this->assertFalse((bool) $session->needs_reconciliation);
        $this->assertSame(0, $this->getJson('/api/v1/attendance/missed-punches')->json('counts.missing_clock_out'));

        Carbon::setTestNow();
    }

    public function test_admin_can_correct_clock_in_time(): void
    {
        Sanctum::actingAs($this->admin);
        Carbon::setTestNow(Carbon::parse('2026-08-14 18:00:00', 'Africa/Nairobi'));

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-14T08:40:00+03:00',
            'direction' => 'in',
        ])->assertCreated();

        $sessionId = EmployeeClockSession::query()->where('employee_id', $this->employee->id)->value('id');

        $this->patchJson('/api/v1/attendance/clock-sessions/'.$sessionId, [
            'clock_in_at' => '2026-08-14 08:05:00',
        ])->assertOk();

        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-14',
            'check_in' => '08:05:00',
            'late_minutes' => 0,
        ]);

        Carbon::setTestNow();
    }

    public function test_punch_outside_lunch_and_clock_out_windows_is_missed(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T08:10:00+03:00',
            'direction' => 'auto',
        ])->assertCreated()->assertJsonPath('action', 'in');

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T10:30:00+03:00',
            'direction' => 'auto',
        ])->assertOk()->assertJsonPath('action', 'missed');

        $this->assertSame(1, EmployeeClockSession::query()->where('employee_id', $this->employee->id)->count());
        $this->assertNull(EmployeeClockSession::query()->where('employee_id', $this->employee->id)->value('clock_out_at'));
    }

    public function test_clock_in_and_lunch_out_without_return_is_half_day(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T08:10:00+03:00',
            'direction' => 'auto',
        ])->assertCreated();

        $this->postJson('/api/v1/attendance/clock-punch', [
            'employee_code' => 'EMP#HIK001',
            'device_no' => 'TERMINAL-01',
            'punched_at' => '2026-08-11T12:45:00+03:00',
            'direction' => 'auto',
        ])->assertOk()->assertJsonPath('action', 'out');

        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-11',
            'status' => 'half_day',
            'check_out' => '12:45:00',
        ]);
    }

    public function test_hr_can_apply_unapplied_terminal_punch_as_applied_by_hr(): void
    {
        Sanctum::actingAs($this->admin);
        Carbon::setTestNow(Carbon::parse('2026-08-14 23:40:00', 'Africa/Nairobi'));

        $device = AttendanceClockDevice::query()
            ->where('organization_id', $this->org->id)
            ->where('device_no', 'TERMINAL-01')
            ->firstOrFail();

        HikvisionEmployeeMapping::query()->create([
            'organization_id' => $this->org->id,
            'attendance_clock_device_id' => $device->id,
            'employee_id' => $this->employee->id,
            'hikvision_employee_no' => 'EMP#HIK001',
            'sync_status' => 'mapped',
        ]);

        $event = HikvisionAccessEvent::query()->create([
            'organization_id' => $this->org->id,
            'attendance_clock_device_id' => $device->id,
            'event_key' => 'hr-apply-'.uniqid(),
            'employee_no' => 'EMP#HIK001',
            'employee_name' => null,
            'event_time' => '2026-08-14 23:05:00',
            'raw_payload' => [],
            'processed_at' => now(),
            'process_error' => HikvisionAccessEvent::OUTSIDE_WINDOW,
        ]);

        $list = $this->getJson('/api/v1/attendance/missed-punches')->assertOk();
        $listed = collect($list->json('unapplied_terminal_punches'))->first(
            fn ($row) => (int) ($row['id'] ?? 0) === (int) $event->id
        );
        $this->assertNotNull($listed);
        $this->assertSame('Hik Vision', $listed['employee_name']);
        $this->assertSame('EMP#HIK001', $listed['employee_code']);

        $this->postJson("/api/v1/attendance/missed-punches/events/{$event->id}/apply")
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('action', 'in');

        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $this->employee->id,
            'attendance_date' => '2026-08-14',
            'source' => 'hr_applied',
            'check_in' => '23:05:00',
        ]);

        $event->refresh();
        $this->assertNotNull($event->processed_at);
        $this->assertNull($event->process_error);
        $this->assertNotNull($event->clock_session_id);

        Carbon::setTestNow();
    }
}
