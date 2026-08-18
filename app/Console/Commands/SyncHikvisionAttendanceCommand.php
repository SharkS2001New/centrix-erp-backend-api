<?php

namespace App\Console\Commands;

use App\Models\AttendanceClockDevice;
use App\Services\Attendance\Hikvision\HikvisionAgentBridge;
use App\Services\Attendance\Hikvision\HikvisionAttendanceSyncService;
use Illuminate\Console\Command;

class SyncHikvisionAttendanceCommand extends Command
{
    protected $signature = 'erp:sync-hikvision-attendance
        {--organization= : Limit to one organization id}
        {--device= : Limit to one Centrix device_no}
        {--from= : ISO start time override}
        {--to= : ISO end time override}';

    protected $description = 'Retry pending Hikvision punches and pull from the terminal during Admin attendance clock windows.';

    /** First hourly run (Africa/Nairobi). */
    public const WINDOW_START_HOUR = 7;

    public const WINDOW_START_MINUTE = 20;

    /** Inclusive end of overnight wrap (02:00). No punches expected after this until morning. */
    public const WINDOW_END_HOUR = 2;

    public const WINDOW_END_MINUTE = 0;

    public static function isInSyncWindow(?\DateTimeInterface $now = null): bool
    {
        $tz = config('app.timezone', 'Africa/Nairobi');
        $clock = $now
            ? \Carbon\Carbon::parse($now)->timezone($tz)
            : now()->timezone($tz);
        $minutes = $clock->hour * 60 + $clock->minute;
        $start = self::WINDOW_START_HOUR * 60 + self::WINDOW_START_MINUTE;
        $end = self::WINDOW_END_HOUR * 60 + self::WINDOW_END_MINUTE;

        return $minutes >= $start || $minutes <= $end;
    }

    public function handle(HikvisionAttendanceSyncService $sync, HikvisionAgentBridge $bridge): int
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
        $totalPulled = 0;
        $offline = 0;

        foreach ($devices as $device) {
            $agentOnline = $bridge->isAgentOnline($device);
            $this->info(sprintf(
                'Syncing %s (%s) — agent %s…',
                $device->device_no,
                $device->host,
                $agentOnline ? 'online' : 'offline/direct',
            ));

            if (! $agentOnline) {
                $offline++;
                $this->warn('  · Agent not recently checked in; LAN pull may fail (pending punches are still retried).');
            }

            $result = $sync->syncDevice(
                $device,
                $from ? \Carbon\Carbon::instance($from) : null,
                $to ? \Carbon\Carbon::instance($to) : null,
            );
            $totalApplied += $result['applied'];
            $totalPulled += $result['pulled'];
            $this->line(sprintf(
                '  pulled=%d applied=%d skipped=%d via_agent=%s',
                $result['pulled'],
                $result['applied'],
                $result['skipped'],
                ! empty($result['via_agent']) ? 'yes' : 'no',
            ));
            foreach (array_slice($result['errors'], 0, 5) as $error) {
                $this->warn("  · {$error}");
            }
        }

        $this->info("Done. Pulled {$totalPulled}, applied {$totalApplied} punch(es). Offline agents: {$offline}/{$devices->count()}.");

        return self::SUCCESS;
    }
}
