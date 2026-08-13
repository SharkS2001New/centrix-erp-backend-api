<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\AttendanceClockDevice;
use App\Models\Organization;
use App\Models\User;
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

    public function test_hikvision_test_connection_endpoint_returns_capabilities(): void
    {
        Http::fake([
            'http://192.168.100.215/*' => Http::sequence()
                ->push('<DeviceInfo><deviceName>Terminal</deviceName><model>DS-K1T904AMF</model></DeviceInfo>', 200)
                ->push(['UserInfoCount' => ['userNumber' => 2]], 200)
                ->push(['AcsEventCap' => ['eventAttribute' => ['attendance', 'other']]], 200)
                ->push(['AcsEventTotalNumCap' => ['supported' => true]], 200)
                ->push([], 404),
        ]);

        $org = $this->org;
        Sanctum::actingAs($this->admin);

        $device = AttendanceClockDevice::create([
            'organization_id' => $org->id,
            'device_no' => 'T001',
            'is_active' => true,
            'provider' => 'hikvision',
            'host' => '192.168.100.215',
            'port' => 80,
            'username' => 'admin',
        ]);
        $device->setPlainPassword('secret');
        $device->save();

        $response = $this->postJson("/api/v1/attendance-clock-devices/{$device->id}/hikvision/test-connection");

        $response->assertOk();
        $response->assertJsonPath('online', true);
        $this->assertNotEmpty($response->json('capabilities'));
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
}
