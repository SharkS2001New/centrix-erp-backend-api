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

    public function test_agent_online_ttl_follows_poll_interval(): void
    {
        $device = new AttendanceClockDevice(['organization_id' => 1]);
        $device->forceFill(['agent_last_seen_at' => now()->subMinutes(4)]);

        $bridge = app(HikvisionAgentBridge::class);

        // Default poll is 5 minutes → online TTL is poll + 120s (~7 minutes).
        $this->assertGreaterThanOrEqual(300, $bridge->pollSeconds($device));
        $this->assertGreaterThanOrEqual(420, $bridge->onlineTtlSeconds($device));
        $this->assertTrue($bridge->isAgentOnline($device));

        $device->forceFill(['agent_last_seen_at' => now()->subMinutes(20)]);
        $this->assertFalse($bridge->isAgentOnline($device));
    }

    public function test_command_wait_covers_one_poll_cycle(): void
    {
        $device = new AttendanceClockDevice(['organization_id' => 1]);
        $bridge = app(HikvisionAgentBridge::class);

        $wait = $bridge->commandWaitSeconds($device);
        $this->assertGreaterThanOrEqual($bridge->pollSeconds($device), $wait - 90);
        $this->assertLessThanOrEqual(HikvisionAgentBridge::MAX_COMMAND_WAIT_SECONDS, $wait);
    }
}
