<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\AttendanceClockDevice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Attendance\Hikvision\HikvisionIsapiClient;
use App\Services\Attendance\Hikvision\HikvisionService;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class HikvisionDeviceManagementTest extends TestCase
{
    use RefreshesErpDatabase;

    protected Organization $org;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
        $this->org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $this->admin = User::where('username', 'admin')->firstOrFail();
    }

    public function test_event_key_is_stable_and_unique_per_serial(): void
    {
        $deviceId = 7;
        $event = [
            'employee_no' => 'EMP001',
            'punched_at' => '2026-08-13T07:50:00+03:00',
            'serial_no' => '998877',
            'major' => 5,
            'minor' => 75,
            'verification_method' => 'card',
            'attendance_status' => 'checkIn',
        ];

        $key1 = HikvisionService::buildEventKey($deviceId, $event);
        $key2 = HikvisionService::buildEventKey($deviceId, $event);

        $this->assertSame($key1, $key2);
        $this->assertStringStartsWith('d7:s998877', $key1);
    }

    public function test_device_serial_string_does_not_collapse_distinct_punches(): void
    {
        $deviceId = 7;
        $shared = [
            'employee_no' => '0003',
            'serial_no' => 'DS-K1T904AMF',
            'major' => 5,
            'minor' => 75,
        ];
        $first = HikvisionService::buildEventKey($deviceId, $shared + ['punched_at' => '2026-08-14T08:05:00+03:00']);
        $second = HikvisionService::buildEventKey($deviceId, $shared + ['punched_at' => '2026-08-14T08:26:00+03:00']);

        $this->assertNotSame($first, $second);
    }

    public function test_test_connection_requires_centrix_attendance_agent(): void
    {
        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T001',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $offline = $this->postJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/test-connection");
        $offline->assertOk();
        $offline->assertJsonPath('online', false);
        $offline->assertJsonPath('agent.name', 'CentrixAttendanceAgent');
        $this->assertStringContainsString('CentrixAttendanceAgent', (string) $offline->json('error'));

        $device->agent_last_seen_at = now();
        $device->save();

        $online = $this->postJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/test-connection");
        $online->assertOk();
        $online->assertJsonPath('online', true);
        $online->assertJsonPath('via_agent', true);
        $online->assertJsonPath('agent.name', 'CentrixAttendanceAgent');
    }

    public function test_hikvision_resolves_misconfigured_port_8000_to_80(): void
    {
        $device = new AttendanceClockDevice([
            'host' => '192.168.100.215',
            'port' => 8000,
            'use_https' => false,
            'provider' => 'hikvision',
        ]);

        $this->assertSame(80, HikvisionIsapiClient::resolvePort($device));
        $this->assertSame(80, HikvisionIsapiClient::normalizeStoredPort(8000, false));
    }

    public function test_agent_ingest_events_is_idempotent(): void
    {
        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-INGEST',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
        ]);
        $device->setPlainPassword('secret');
        $device->capabilities_json = ['features' => ['events' => true]];
        $device->save();

        $payload = [
            'events' => [[
                'employee_no' => 'EMP#HIK001',
                'punched_at' => '2026-08-13T08:00:00+03:00',
                'serial_no' => '555001',
                'attendance_status' => 'checkIn',
                'verification_method' => 'fingerprint',
            ]],
        ];

        $url = "/api/v1/attendance-clock-devices/{$device->id}/hikvision/agent/ingest-events";
        $first = $this->postJson($url, $payload);
        $first->assertOk();
        $first->assertJsonPath('stored', 1);

        $second = $this->postJson($url, $payload);
        $second->assertOk();
        $second->assertJsonPath('stored', 0);
        $second->assertJsonPath('skipped', 1);
    }

    public function test_create_and_delete_card_endpoints(): void
    {
        Http::fake([
            'http://192.168.100.215/*' => Http::sequence()
                ->push(['status' => 'OK'], 200)
                ->push(['status' => 'OK'], 200),
        ]);

        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-CARD',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
            'capabilities_json' => ['features' => ['cards' => true]],
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $create = $this->postJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/cards", [
            'employeeNo' => 'EMP#HIK001',
            'cardNo' => '12345678',
        ]);
        $create->assertOk();

        $delete = $this->deleteJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/cards", [
            'employeeNo' => 'EMP#HIK001',
            'cardNo' => '12345678',
        ]);
        $delete->assertOk();
    }

    public function test_agent_command_queue_round_trip(): void
    {
        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-AGENT',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
            'agent_last_seen_at' => now(),
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $bridge = app(\App\Services\Attendance\Hikvision\HikvisionAgentBridge::class);

        \App\Models\HikvisionAgentCommand::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'attendance_clock_device_id' => $device->id,
            'method' => 'GET',
            'path' => '/ISAPI/System/deviceInfo',
            'accept' => 'json',
            'status' => 'pending',
            'created_at' => now(),
            'expires_at' => now()->addMinute(),
        ]);

        $pending = $bridge->pullPendingCommands($device);
        $this->assertCount(1, $pending);

        $bridge->submitCommandResult($device, $pending[0]['id'], [
            'success' => true,
            'status' => 200,
            'body' => '<DeviceInfo><model>DS-K1T904AMF</model></DeviceInfo>',
            'headers' => ['Content-Type' => ['application/xml']],
            'agent_version' => '2.0.0',
        ]);

        $command = \App\Models\HikvisionAgentCommand::query()->find($pending[0]['id']);
        $this->assertSame('completed', $command->status);
        $this->assertSame('2.0.0', $device->fresh()->agent_version);
    }

    public function test_delete_users_writes_audit_log(): void
    {
        Http::fake([
            'http://192.168.100.215/*' => Http::response(['status' => 'OK'], 200),
        ]);

        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-AUDIT',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
            'capabilities_json' => ['features' => ['users' => true]],
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $this->deleteJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/users", [
            'employee_nos' => ['EMP001'],
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'hikvision.delete_users',
            'table_name' => 'attendance_clock_devices',
            'record_id' => (string) $device->id,
        ]);
    }

    public function test_acs_event_datetime_never_uses_zulu_suffix(): void
    {
        $utc = new \DateTimeImmutable('2026-08-13T15:38:00Z');
        $formatted = HikvisionIsapiClient::formatAcsEventDateTime($utc, true);

        $this->assertStringNotContainsString('Z', $formatted);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $formatted);
    }

    public function test_live_punch_sends_required_acs_event_fields(): void
    {
        Http::fake([
            'http://192.168.100.215/*' => Http::response([
                'AcsEvent' => [
                    'numOfMatches' => 1,
                    'responseStatusStrg' => 'OK',
                    'InfoList' => [[
                        'employeeNoString' => 'EMP001',
                        'time' => '2026-08-13T18:40:00+03:00',
                        'currentVerifyMode' => 'fingerprint',
                        'attendanceStatus' => 'checkIn',
                        'serialNo' => '99',
                    ]],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-LIVE',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
            'capabilities_json' => ['features' => ['events' => true]],
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $res = $this->postJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/test/live-punch", [
            'since' => now()->subSeconds(20)->toIso8601String(),
            'apply' => false,
        ]);

        $res->assertOk();
        $res->assertJsonPath('fingerprint_detected', true);
        $res->assertJsonPath('latest.employee_no', 'EMP001');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/ISAPI/AccessControl/AcsEvent')) {
                return false;
            }
            $cond = $request->data()['AcsEventCond'] ?? [];

            return ($cond['major'] ?? null) === 5
                && ($cond['minor'] ?? null) === 75
                && isset($cond['searchID'], $cond['startTime'], $cond['endTime'], $cond['searchResultPosition'], $cond['maxResults'])
                && ! str_contains((string) $cond['startTime'], 'Z');
        });
    }

    public function test_live_punch_retries_without_event_attribute_on_bad_parameters(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response([
                    'statusCode' => 6,
                    'statusString' => 'Invalid Content',
                    'subStatusCode' => 'badParameters',
                    'errorCode' => 1610612737,
                    'errorMsg' => '0x60000001',
                ], 400);
            }

            return Http::response([
                'AcsEvent' => [
                    'numOfMatches' => 0,
                    'InfoList' => [],
                    'responseStatusStrg' => 'OK',
                ],
            ], 200);
        });

        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-LIVE-RETRY',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
            'capabilities_json' => ['features' => ['events' => true]],
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $res = $this->postJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/test/live-punch", [
            'since' => now()->subSeconds(20)->toIso8601String(),
            'apply' => false,
        ]);

        $res->assertOk();
        $res->assertJsonPath('fingerprint_detected', false);

        $acsBodies = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['AcsEventCond'] ?? null)
            ->filter()
            ->values();

        $this->assertGreaterThanOrEqual(2, $acsBodies->count());
        $this->assertSame(5, $acsBodies[0]['major'] ?? null);
        $this->assertSame(75, $acsBodies[0]['minor'] ?? null);
        $this->assertArrayNotHasKey('eventAttribute', $acsBodies[1]);
    }

    public function test_live_punch_retries_when_first_event_search_is_empty(): void
    {
        Http::fake(function () {
            static $calls = 0;
            $calls++;
            if ($calls === 1) {
                return Http::response([
                    'AcsEvent' => [
                        'numOfMatches' => 0,
                        'InfoList' => [],
                        'responseStatusStrg' => 'OK',
                    ],
                ], 200);
            }

            return Http::response([
                'AcsEvent' => [
                    'numOfMatches' => 1,
                    'responseStatusStrg' => 'OK',
                    'InfoList' => [[
                        'employeeNoString' => '0003',
                        'time' => '2026-08-14T17:40:00+03:00',
                        'currentVerifyMode' => 'fingerprint',
                        'minor' => 75,
                        'major' => 5,
                        'serialNo' => '101',
                    ]],
                ],
            ], 200);
        });

        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-LIVE-EMPTY',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
            'capabilities_json' => ['features' => ['events' => true]],
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $res = $this->postJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/test/live-punch", [
            'since' => now()->subSeconds(20)->toIso8601String(),
            'apply' => false,
        ]);

        $res->assertOk();
        $res->assertJsonPath('fingerprint_detected', true);
        $res->assertJsonPath('latest.employee_no', '0003');
    }

    public function test_user_search_uses_short_search_id_and_max_30(): void
    {
        Http::fake([
            'http://192.168.100.215/*' => Http::response([
                'UserInfoSearch' => [
                    'responseStatusStrg' => 'OK',
                    'numOfMatches' => 1,
                    'UserInfo' => [
                        'employeeNoString' => '0003',
                        'name' => 'Ada',
                        'numOfFP' => 2,
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-USERS',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
            'capabilities_json' => ['features' => ['users' => true]],
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $res = $this->postJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/users/search", [
            'maxResults' => 50,
        ]);
        $res->assertOk();
        $res->assertJsonPath('users.0.employeeNo', '0003');
        $res->assertJsonPath('users.0.numOfFP', 2);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/Search')) {
                return false;
            }
            $cond = $request->data()['UserInfoSearchCond'] ?? [];
            $searchId = (string) ($cond['searchID'] ?? '');

            return strlen($searchId) <= 16
                && ! str_contains($searchId, '-')
                && (int) ($cond['maxResults'] ?? 0) <= 30
                && array_key_exists('searchResultPosition', $cond);
        });
    }

    public function test_agent_ingest_applies_punch_via_employee_mapping(): void
    {
        Sanctum::actingAs($this->admin);

        $template = \App\Models\Employee::query()->where('organization_id', $this->org->id)->firstOrFail();
        $shift = \App\Models\WorkShift::query()->create([
            'organization_id' => $this->org->id,
            'shift_code' => 'HV'.uniqid(),
            'shift_name' => 'Hikvision map shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'works_saturday' => true,
            'works_sunday' => true,
            'works_public_holidays' => true,
            'is_active' => true,
        ]);
        $employee = \App\Models\Employee::query()->create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP#MAPPED99',
            'payroll_number' => 'EMP#MAPPED99',
            'first_name' => 'Map',
            'last_name' => 'Test',
            'full_name' => 'Map Test',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2024-01-01',
            'base_salary' => 50000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-MAP-IN',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        // Terminal ID does not match Centrix employee_code — mapping must resolve it.
        \App\Models\HikvisionEmployeeMapping::query()->create([
            'organization_id' => $this->org->id,
            'attendance_clock_device_id' => $device->id,
            'employee_id' => $employee->id,
            'hikvision_employee_no' => 'TERM-ONLY-99',
            'sync_status' => 'mapped',
        ]);

        $url = "/api/v1/attendance-clock-devices/{$device->id}/hikvision/agent/ingest-events";
        $res = $this->postJson($url, [
            'events' => [[
                'employee_no' => 'TERM-ONLY-99',
                'punched_at' => '2026-08-13T08:05:00+03:00',
                'serial_no' => 'map-in-1',
                'attendance_status' => 'checkIn',
                'verification_method' => 'fingerprint',
            ]],
        ]);

        $res->assertOk();
        $res->assertJsonPath('stored', 1);
        $res->assertJsonPath('applied', 1);

        $this->assertDatabaseHas('employee_clock_sessions', [
            'employee_id' => $employee->id,
            'device_identifier' => 'T-MAP-IN',
            'source' => 'clock_device',
        ]);
        $this->assertDatabaseHas('hikvision_access_events', [
            'attendance_clock_device_id' => $device->id,
            'employee_no' => 'TERM-ONLY-99',
            'serial_no' => 'map-in-1',
        ]);
        $this->assertNotNull(
            \App\Models\HikvisionAccessEvent::query()
                ->where('serial_no', 'map-in-1')
                ->value('processed_at')
        );
    }

    public function test_new_punch_in_same_hour_applies_after_session_deleted(): void
    {
        Sanctum::actingAs($this->admin);

        $template = \App\Models\Employee::query()->where('organization_id', $this->org->id)->firstOrFail();
        $shift = \App\Models\WorkShift::query()->create([
            'organization_id' => $this->org->id,
            'shift_code' => 'DL'.uniqid(),
            'shift_name' => 'Delete then punch shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'works_saturday' => true,
            'works_sunday' => true,
            'works_public_holidays' => true,
            'is_active' => true,
        ]);
        $employee = \App\Models\Employee::query()->create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP#REDO01',
            'payroll_number' => 'EMP#REDO01',
            'first_name' => 'Redo',
            'last_name' => 'Punch',
            'full_name' => 'Redo Punch',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2024-01-01',
            'base_salary' => 50000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-REDO',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        \App\Models\HikvisionEmployeeMapping::query()->create([
            'organization_id' => $this->org->id,
            'attendance_clock_device_id' => $device->id,
            'employee_id' => $employee->id,
            'hikvision_employee_no' => '0003',
            'sync_status' => 'mapped',
        ]);

        $url = "/api/v1/attendance-clock-devices/{$device->id}/hikvision/agent/ingest-events";
        $this->postJson($url, [
            'events' => [[
                'employee_no' => '0003',
                'punched_at' => '2026-08-14T08:05:00+03:00',
                'serial_no' => '1001',
                'attendance_status' => 'checkIn',
                'verification_method' => 'fingerprint',
                'minor' => 75,
            ]],
        ])->assertOk()->assertJsonPath('applied', 1);

        $sessionId = \App\Models\EmployeeClockSession::query()->where('employee_id', $employee->id)->value('id');
        $this->assertNotNull($sessionId);
        $this->deleteJson('/api/v1/attendance/clock-sessions/'.$sessionId)->assertNoContent();

        $second = $this->postJson($url, [
            'events' => [[
                'employee_no' => '0003',
                'punched_at' => '2026-08-14T08:26:00+03:00',
                'serial_no' => '1002',
                'attendance_status' => 'checkIn',
                'verification_method' => 'fingerprint',
                'minor' => 75,
            ]],
        ]);
        $second->assertOk();
        $second->assertJsonPath('applied', 1);
        $this->assertSame(1, \App\Models\EmployeeClockSession::query()->where('employee_id', $employee->id)->count());
        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-14',
            'check_in' => '08:26:00',
        ]);
    }

    public function test_agent_command_poll_includes_admin_sync_interval(): void
    {
        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-POLL',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $this->getJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/agent/commands/pending")
            ->assertOk()
            ->assertJsonPath('poll_interval_seconds', 300)
            ->assertJsonPath('commands', []);
    }

    public function test_map_employee_reprocesses_pending_punches(): void
    {
        Sanctum::actingAs($this->admin);

        $template = \App\Models\Employee::query()->where('organization_id', $this->org->id)->firstOrFail();
        $shift = \App\Models\WorkShift::query()->create([
            'organization_id' => $this->org->id,
            'shift_code' => 'HR'.uniqid(),
            'shift_name' => 'Hikvision retry shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'works_saturday' => true,
            'works_sunday' => true,
            'works_public_holidays' => true,
            'is_active' => true,
        ]);
        $employee = \App\Models\Employee::query()->create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP#RETRY77',
            'payroll_number' => 'EMP#RETRY77',
            'first_name' => 'Retry',
            'last_name' => 'Test',
            'full_name' => 'Retry Test',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2024-01-01',
            'base_salary' => 50000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-RETRY',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $ingest = $this->postJson(
            "/api/v1/attendance-clock-devices/{$device->id}/hikvision/agent/ingest-events",
            [
                'events' => [[
                    'employee_no' => 'TERM-ONLY-77',
                    'punched_at' => '2026-08-13T09:10:00+03:00',
                    'serial_no' => 'retry-1',
                    'attendance_status' => 'checkIn',
                ]],
            ]
        );
        $ingest->assertOk();
        $ingest->assertJsonPath('stored', 1);
        $ingest->assertJsonPath('applied', 0);
        $this->assertNull(
            \App\Models\HikvisionAccessEvent::query()->where('serial_no', 'retry-1')->value('processed_at')
        );

        $map = $this->postJson(
            "/api/v1/attendance-clock-devices/{$device->id}/hikvision/sync/employees/map",
            [
                'employee_id' => $employee->id,
                'hikvision_employee_no' => 'TERM-ONLY-77',
            ]
        );
        $map->assertCreated();
        $map->assertJsonPath('reprocessed.applied', 1);

        $this->assertNotNull(
            \App\Models\HikvisionAccessEvent::query()->where('serial_no', 'retry-1')->value('processed_at')
        );
        $this->assertDatabaseHas('employee_clock_sessions', [
            'employee_id' => $employee->id,
            'device_identifier' => 'T-RETRY',
        ]);
    }

    public function test_ingest_sanitizes_undefined_fields_and_lists_nairobi_time(): void
    {
        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-TZ-FIELDS',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $ingest = $this->postJson(
            "/api/v1/attendance-clock-devices/{$device->id}/hikvision/agent/ingest-events",
            [
                'events' => [[
                    'employee_no' => '0003',
                    'punched_at' => '2026-08-14T05:32:09.000000Z',
                    'serial_no' => 'tz-1',
                    'attendance_status' => 'undefined',
                    'verification_method' => 'undefined',
                    'major' => 5,
                    'minor' => 75,
                    'raw' => [
                        'time' => '2026-08-14T05:32:09.000000Z',
                        'employeeNoString' => '0003',
                        'attendanceStatus' => 'undefined',
                        'major' => 5,
                        'minor' => 75,
                    ],
                ]],
            ]
        );
        $ingest->assertOk();
        $ingest->assertJsonPath('stored', 1);

        $this->assertDatabaseHas('hikvision_access_events', [
            'attendance_clock_device_id' => $device->id,
            'serial_no' => 'tz-1',
            'attendance_status' => 'checkIn',
            'verification_method' => 'fingerprint',
        ]);

        $list = $this->getJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/events/stored");
        $list->assertOk();
        $this->assertSame('checkIn', $list->json('events.data.0.attendance_status'));
        $this->assertSame('fingerprint', $list->json('events.data.0.verification_method'));
        $this->assertStringContainsString('2026-08-14T05:32:09+03:00', (string) $list->json('events.data.0.event_time'));
        $this->assertStringNotContainsString('Z', (string) $list->json('events.data.0.event_time'));
    }

    public function test_missed_punches_lists_unapplied_terminal_events(): void
    {
        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-MISSED',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $this->postJson(
            "/api/v1/attendance-clock-devices/{$device->id}/hikvision/agent/ingest-events",
            [
                'events' => [[
                    'employee_no' => 'UNMAPPED-88',
                    'punched_at' => '2026-08-14T08:10:00+03:00',
                    'serial_no' => 'miss-1',
                    'attendance_status' => 'checkIn',
                    'minor' => 75,
                ]],
            ]
        )->assertOk();

        $res = $this->getJson('/api/v1/attendance/missed-punches');
        $res->assertOk();
        $res->assertJsonPath('counts.unapplied_terminal_punches', 1);
        $this->assertSame('UNMAPPED-88', $res->json('unapplied_terminal_punches.0.employee_no'));
        $this->assertSame('T-MISSED', $res->json('unapplied_terminal_punches.0.device_no'));
    }

    public function test_same_hour_duplicate_terminal_punches_are_not_listed_as_missed(): void
    {
        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-DEDUP',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $this->postJson(
            "/api/v1/attendance-clock-devices/{$device->id}/hikvision/agent/ingest-events",
            [
                'events' => [
                    [
                        'employee_no' => 'UNMAPPED-55',
                        'punched_at' => '2026-08-14T08:10:00+03:00',
                        'serial_no' => 'dup-1',
                        'minor' => 75,
                    ],
                    [
                        'employee_no' => 'UNMAPPED-55',
                        'punched_at' => '2026-08-14T08:18:00+03:00',
                        'serial_no' => 'dup-2',
                        'minor' => 75,
                    ],
                    [
                        'employee_no' => 'UNMAPPED-55',
                        'punched_at' => '2026-08-14T08:41:00+03:00',
                        'serial_no' => 'dup-3',
                        'minor' => 75,
                    ],
                ],
            ]
        )->assertOk();

        $this->assertSame(
            3,
            \App\Models\HikvisionAccessEvent::query()->where('attendance_clock_device_id', $device->id)->count()
        );

        $res = $this->getJson('/api/v1/attendance/missed-punches');
        $res->assertOk();
        $this->assertSame(1, $res->json('counts.unapplied_terminal_punches'));
        $this->assertSame(2, $res->json('counts.duplicate_punches'));
        $this->assertSame('UNMAPPED-55', $res->json('unapplied_terminal_punches.0.employee_no'));
        $this->assertSame('UNMAPPED-55', $res->json('duplicate_punches.0.employee_no'));
        $this->assertSame('UNMAPPED-55', $res->json('duplicate_punches.1.employee_no'));
    }

    public function test_same_hour_duplicate_mapped_punches_are_listed_for_hr(): void
    {
        Sanctum::actingAs($this->admin);

        $template = \App\Models\Employee::query()->where('organization_id', $this->org->id)->firstOrFail();
        $shift = \App\Models\WorkShift::query()->create([
            'organization_id' => $this->org->id,
            'shift_code' => 'DP'.uniqid(),
            'shift_name' => 'Duplicate punch shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'lunch_minutes' => 60,
            'lunch_required' => true,
            'works_saturday' => true,
            'works_sunday' => true,
            'works_public_holidays' => true,
            'is_active' => true,
        ]);
        $employee = \App\Models\Employee::query()->create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->admin->branch_id,
            'department_id' => $template->department_id,
            'position_id' => $template->position_id,
            'shift_id' => $shift->id,
            'employee_code' => 'EMP#DUP01',
            'payroll_number' => 'EMP#DUP01',
            'first_name' => 'Dup',
            'last_name' => 'Punch',
            'full_name' => 'Dup Punch',
            'employment_status' => 'active',
            'employment_type' => 'permanent',
            'pay_frequency' => 'monthly',
            'hire_date' => '2024-01-01',
            'base_salary' => 50000,
            'country' => 'Kenya',
            'is_active' => true,
        ]);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-DUP-HR',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        \App\Models\HikvisionEmployeeMapping::query()->create([
            'organization_id' => $this->org->id,
            'attendance_clock_device_id' => $device->id,
            'employee_id' => $employee->id,
            'hikvision_employee_no' => '0008',
            'sync_status' => 'mapped',
        ]);

        $this->postJson(
            "/api/v1/attendance-clock-devices/{$device->id}/hikvision/agent/ingest-events",
            [
                'events' => [
                    [
                        'employee_no' => '0008',
                        'punched_at' => '2026-08-14T08:05:00+03:00',
                        'serial_no' => 'dup-hr-1',
                        'attendance_status' => 'checkIn',
                        'verification_method' => 'fingerprint',
                        'minor' => 75,
                    ],
                    [
                        'employee_no' => '0008',
                        'punched_at' => '2026-08-14T08:22:00+03:00',
                        'serial_no' => 'dup-hr-2',
                        'attendance_status' => 'checkIn',
                        'verification_method' => 'fingerprint',
                        'minor' => 75,
                    ],
                ],
            ]
        )->assertOk()->assertJsonPath('applied', 1);

        $this->assertSame(1, \App\Models\EmployeeClockSession::query()->where('employee_id', $employee->id)->count());
        $this->assertDatabaseHas('employee_attendance', [
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-14',
            'check_in' => '08:05:00',
        ]);
        $this->assertDatabaseHas('hikvision_access_events', [
            'attendance_clock_device_id' => $device->id,
            'serial_no' => 'dup-hr-2',
            'process_error' => \App\Models\HikvisionAccessEvent::DUPLICATE_PUNCH,
        ]);

        $res = $this->getJson('/api/v1/attendance/missed-punches');
        $res->assertOk();
        $this->assertSame(0, $res->json('counts.unapplied_terminal_punches'));
        $this->assertSame(1, $res->json('counts.duplicate_punches'));
        $this->assertSame('0008', $res->json('duplicate_punches.0.employee_no'));
    }

    public function test_sync_attendance_requires_online_agent(): void
    {
        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-SYNC-OFF',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
            'capabilities_json' => ['features' => ['events' => true]],
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $res = $this->postJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/sync/attendance");
        $res->assertOk();
        $res->assertJsonPath('pulled', 0);
        $this->assertNotEmpty($res->json('errors'));
        $this->assertStringContainsString('CentrixAttendanceAgent', (string) $res->json('errors.0'));
    }

    public function test_hr_attendance_sync_from_devices_pulls_org_clocks(): void
    {
        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $this->org->id,
            'device_no' => 'T-HR-SYNC',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
            'capabilities_json' => ['features' => ['events' => true]],
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $res = $this->postJson('/api/v1/attendance/sync-from-devices');
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $res->json('devices'));
        $res->assertJsonPath('pulled', 0);
        $this->assertNotEmpty($res->json('errors'));
        $this->assertStringContainsString('CentrixAttendanceAgent', (string) $res->json('errors.0'));
    }
}
