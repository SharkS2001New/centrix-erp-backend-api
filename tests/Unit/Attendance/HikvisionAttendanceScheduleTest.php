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
            Carbon::parse('2026-08-17 07:00:00', $tz),
        ));
    }

    public function test_agent_online_ttl_tracks_recent_command_heartbeat(): void
    {
        $device = new AttendanceClockDevice(['organization_id' => 1]);
        $device->forceFill(['agent_last_seen_at' => now()->subSeconds(90)]);

        $bridge = app(HikvisionAgentBridge::class);

        // The agent polls Centrix for commands every few seconds, so the heartbeat
        // window stays short even if attendance auto-sync is hourly.
        $this->assertGreaterThanOrEqual(300, $bridge->pollSeconds($device));
        $this->assertSame(120, $bridge->onlineTtlSeconds($device));
        $this->assertTrue($bridge->isAgentOnline($device));

        $device->forceFill(['agent_last_seen_at' => now()->subSeconds(121)]);
        $this->assertFalse($bridge->isAgentOnline($device));
    }

    public function test_command_wait_is_bounded_for_stale_agents(): void
    {
        $device = new AttendanceClockDevice(['organization_id' => 1]);
        $bridge = app(HikvisionAgentBridge::class);

        $wait = $bridge->commandWaitSeconds($device);
        $this->assertSame(HikvisionAgentBridge::MIN_COMMAND_WAIT_SECONDS, $wait);
    }
}
