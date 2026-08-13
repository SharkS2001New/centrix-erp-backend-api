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
}
