<?php

namespace App\Console\Commands;

use App\Models\AttendanceClockDevice;
use App\Services\Attendance\Hikvision\HikvisionAgentBridge;
use App\Support\AppTimezone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorAttendanceAgentsCommand extends Command
{
    protected $signature = 'erp:monitor-attendance-agents';

    protected $description = 'Check Hikvision attendance agent check-ins and log when devices appear offline.';

    public function handle(HikvisionAgentBridge $bridge): int
    {
        $now = AppTimezone::now();

        $devices = AttendanceClockDevice::query()
            ->where('provider', 'hikvision')
            ->where('is_active', true)
            ->get();

        $offline = [];
        foreach ($devices as $device) {
            $seen = AppTimezone::normalize($device->agent_last_seen_at);
            if (! $bridge->isAgentOnline($device)) {
                $offline[] = [
                    'device_no' => $device->device_no,
                    'host' => $device->host,
                    'last_seen_at' => $device->agent_last_seen_at,
                    'online_ttl_seconds' => $bridge->onlineTtlSeconds($device),
                ];
            }
        }

        if (! empty($offline)) {
            $count = count($offline);
            $this->warn("{$count} Hikvision attendance agent(s) appear offline or not checking in recently.");
            Log::warning('Hikvision attendance agents offline', ['devices' => $offline]);
        } else {
            $this->info('All Hikvision attendance agents checked in recently.');
        }

        return self::SUCCESS;
    }
}
