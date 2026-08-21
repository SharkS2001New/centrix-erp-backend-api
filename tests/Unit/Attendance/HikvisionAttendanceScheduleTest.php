<?php

namespace Tests\Unit\Attendance;

use App\Console\Commands\SyncHikvisionAttendanceCommand;
use App\Models\AttendanceClockDevice;
use App\Services\Attendance\Hikvision\HikvisionAgentBridge;
use Carbon\Carbon;
use Tests\TestCase;

class HikvisionAttendanceScheduleTest extends TestCase
{
    public function test_sync_window_covers_workday_and_overnight_wrap(): void
    {
        $tz = 'Africa/Nairobi';

        $this->assertTrue(SyncHikvisionAttendanceCommand::isInSyncWindow(
            Carbon::parse('2026-08-17 06:00:00', $tz),
        ));
        $this->assertTrue(SyncHikvisionAttendanceCommand::isInSyncWindow(
            Carbon::parse('2026-08-17 07:20:00', $tz),
        ));
        $this->assertTrue(SyncHikvisionAttendanceCommand::isInSyncWindow(
            Carbon::parse('2026-08-17 14:20:00', $tz),
        ));
        $this->assertTrue(SyncHikvisionAttendanceCommand::isInSyncWindow(
            Carbon::parse('2026-08-17 02:00:00', $tz),
        ));
        $this->assertFalse(SyncHikvisionAttendanceCommand::isInSyncWindow(
            Carbon::parse('2026-08-17 03:20:00', $tz),
        ));
        $this->assertFalse(SyncHikvisionAttendanceCommand::isInSyncWindow(
            Carbon::parse('2026-08-17 05:59:00', $tz),
        ));
    }

    public function test_punch_upload_follows_admin_clock_windows_not_all_day(): void
    {
        $settings = \App\Services\Attendance\HrAttendanceSettingsResolver::defaults();
        $tz = 'Africa/Nairobi';

        $this->assertTrue(\App\Services\Attendance\HrAttendanceSettingsResolver::isInPunchUploadWindow(
            $settings,
            Carbon::parse('2026-08-18 08:05:00', $tz),
        ));
        $this->assertTrue(\App\Services\Attendance\HrAttendanceSettingsResolver::isInPunchUploadWindow(
            $settings,
            Carbon::parse('2026-08-18 12:45:00', $tz),
        ));
        $this->assertTrue(\App\Services\Attendance\HrAttendanceSettingsResolver::isInPunchUploadWindow(
            $settings,
            Carbon::parse('2026-08-18 15:10:00', $tz),
        ));
        $this->assertTrue(\App\Services\Attendance\HrAttendanceSettingsResolver::isInPunchUploadWindow(
            $settings,
            Carbon::parse('2026-08-18 17:10:00', $tz),
        ));
        $this->assertTrue(\App\Services\Attendance\HrAttendanceSettingsResolver::isInPunchUploadWindow(
            $settings,
            Carbon::parse('2026-08-18 07:51:00', $tz),
        ));
        $this->assertTrue(\App\Services\Attendance\HrAttendanceSettingsResolver::isInPunchUploadWindow(
            $settings,
            Carbon::parse('2026-08-18 10:15:00', $tz),
        ));
        $this->assertFalse(\App\Services\Attendance\HrAttendanceSettingsResolver::isInPunchUploadWindow(
            $settings,
            Carbon::parse('2026-08-18 10:25:00', $tz),
        ));
        $this->assertFalse(\App\Services\Attendance\HrAttendanceSettingsResolver::isInPunchUploadWindow(
            $settings,
            Carbon::parse('2026-08-18 03:20:00', $tz),
        ));
        $this->assertFalse(\App\Services\Attendance\HrAttendanceSettingsResolver::isInPunchUploadWindow(
            $settings,
            Carbon::parse('2026-08-18 11:00:00', $tz),
        ));
    }

    public function test_agent_online_ttl_covers_health_check_interval(): void
    {
        $device = new AttendanceClockDevice(['organization_id' => 0]);
        $bridge = app(HikvisionAgentBridge::class);

        $poll = $bridge->pollSeconds($device);
        $ttl = $bridge->onlineTtlSeconds($device);
        $this->assertGreaterThanOrEqual(60, $poll);
        $this->assertSame(max(HikvisionAgentBridge::MIN_ONLINE_SECONDS, ($poll * 3) + 300), $ttl);

        $device->forceFill(['agent_last_seen_at' => now()->subSeconds($poll)]);
        $this->assertTrue($bridge->isAgentOnline($device));

        $device->forceFill(['agent_last_seen_at' => now()->subSeconds($ttl + 1)]);
        $this->assertFalse($bridge->isAgentOnline($device));
    }

    public function test_command_wait_is_bounded_for_stale_agents(): void
    {
        $device = new AttendanceClockDevice(['organization_id' => 1]);
        $bridge = app(HikvisionAgentBridge::class);

        $wait = $bridge->commandWaitSeconds($device);
        $this->assertSame(HikvisionAgentBridge::MIN_COMMAND_WAIT_SECONDS, $wait);
    }

    public function test_recent_checkin_grace_keeps_agent_usable(): void
    {
        $device = new AttendanceClockDevice(['organization_id' => 0]);
        $bridge = app(HikvisionAgentBridge::class);
        $ttl = $bridge->onlineTtlSeconds($device);

        $device->forceFill([
            'agent_last_seen_at' => now()->subSeconds($ttl + 60),
        ]);
        $this->assertFalse($bridge->isAgentOnline($device));
        $this->assertTrue($bridge->hasRecentCheckIn($device));
        $this->assertTrue($bridge->shouldUseAgent($device));
    }
}
