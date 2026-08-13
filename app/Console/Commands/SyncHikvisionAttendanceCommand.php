<?php

namespace App\Console\Commands;

use App\Models\AttendanceClockDevice;
use App\Services\Attendance\Hikvision\HikvisionAttendanceSyncService;
use Illuminate\Console\Command;

class SyncHikvisionAttendanceCommand extends Command
{
    protected $signature = 'erp:sync-hikvision-attendance
        {--organization= : Limit to one organization id}
        {--device= : Limit to one Centrix device_no}
        {--from= : ISO start time override}
        {--to= : ISO end time override}';

    protected $description = 'Pull punches from Hikvision via CentrixAttendanceAgent (LAN bridge). Prefer the agent service for ongoing sync; this command also uses the agent when online.';

    public function handle(HikvisionAttendanceSyncService $sync): int
    {
        $query = AttendanceClockDevice::query()
            ->where('is_active', true)
            ->where('provider', 'hikvision')
            ->whereNotNull('host')
            ->where('host', '!=', '');

        if ($org = $this->option('organization')) {
            $query->where('organization_id', (int) $org);
        }
        if ($deviceNo = $this->option('device')) {
            $query->where('device_no', $deviceNo);
        }

        $devices = $query->get();
        if ($devices->isEmpty()) {
            $this->warn('No active Hikvision clock devices with a host configured.');

            return self::SUCCESS;
        }

        $from = $this->option('from') ? new \DateTimeImmutable((string) $this->option('from')) : null;
        $to = $this->option('to') ? new \DateTimeImmutable((string) $this->option('to')) : null;

        $totalApplied = 0;
        foreach ($devices as $device) {
            $this->info("Syncing {$device->device_no} ({$device->host})…");
            $result = $sync->syncDevice(
                $device,
                $from ? \Carbon\Carbon::instance($from) : null,
                $to ? \Carbon\Carbon::instance($to) : null,
            );
            $totalApplied += $result['applied'];
            $this->line(
                "  pulled={$result['pulled']} applied={$result['applied']} skipped={$result['skipped']}"
            );
            foreach (array_slice($result['errors'], 0, 5) as $error) {
                $this->warn("  · {$error}");
            }
        }

        $this->info("Done. Applied {$totalApplied} punch(es).");

        return self::SUCCESS;
    }
}
