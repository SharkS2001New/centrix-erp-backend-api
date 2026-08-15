<?php

namespace App\Jobs;

use App\Models\AttendanceClockDevice;
use App\Services\Attendance\Hikvision\HikvisionAttendanceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncHikvisionDeviceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $deviceId)
    {
    }

    public function handle(HikvisionAttendanceSyncService $sync): void
    {
        $device = AttendanceClockDevice::find($this->deviceId);
        if (! $device) {
            return;
        }

        try {
            $sync->syncDevice($device);
        } catch (\Throwable $e) {
            // Job should not bubble up; log and continue.
            \Illuminate\Support\Facades\Log::warning('SyncHikvisionDeviceJob failed', [
                'device_id' => $this->deviceId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
