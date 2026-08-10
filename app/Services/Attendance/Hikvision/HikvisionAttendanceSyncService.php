<?php

namespace App\Services\Attendance\Hikvision;

use App\Models\AttendanceClockDevice;
use App\Services\Attendance\AttendanceClockPunchService;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class HikvisionAttendanceSyncService
{
    public function __construct(
        protected AttendanceClockPunchService $punchService,
    ) {
    }

    /**
     * @return array{pulled: int, applied: int, skipped: int, errors: list<string>}
     */
    public function syncDevice(AttendanceClockDevice $device, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = $to ?? AppTimezone::now();
        $from = $from ?? (
            $device->last_event_at
                ? Carbon::parse($device->last_event_at)->subMinute()
                : AppTimezone::now()->subDay()
        );

        $result = [
            'pulled' => 0,
            'applied' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            $client = new HikvisionIsapiClient($device);
            $events = $client->fetchAccessEvents($from, $to);
        } catch (\Throwable $e) {
            $device->last_synced_at = AppTimezone::now();
            $device->last_sync_error = mb_substr($e->getMessage(), 0, 500);
            $device->save();

            return [
                'pulled' => 0,
                'applied' => 0,
                'skipped' => 0,
                'errors' => [$e->getMessage()],
            ];
        }

        $result['pulled'] = count($events);
        $latestEvent = $device->last_event_at
            ? Carbon::parse($device->last_event_at)
            : null;

        usort($events, static fn ($a, $b) => strcmp($a['punched_at'], $b['punched_at']));

        foreach ($events as $event) {
            try {
                $direction = $this->mapAttendanceStatus($event['attendance_status'] ?? null);
                $this->punchService->punch([
                    'organization_id' => (int) $device->organization_id,
                    'employee_code' => $event['employee_no'],
                    'device_no' => $device->device_no,
                    'punched_at' => $event['punched_at'],
                    'direction' => $direction,
                ]);
                $result['applied']++;
                $at = Carbon::parse($event['punched_at']);
                if (! $latestEvent || $at->gt($latestEvent)) {
                    $latestEvent = $at;
                }
            } catch (ValidationException $e) {
                $result['skipped']++;
                $msg = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                $result['errors'][] = "{$event['employee_no']} @ {$event['punched_at']}: {$msg}";
            } catch (\Throwable $e) {
                $result['skipped']++;
                $result['errors'][] = "{$event['employee_no']} @ {$event['punched_at']}: {$e->getMessage()}";
                Log::warning('Hikvision punch sync failed', [
                    'device_no' => $device->device_no,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $device->last_synced_at = AppTimezone::now();
        $device->last_sync_error = $result['errors'] === []
            ? null
            : mb_substr(implode(' | ', array_slice($result['errors'], 0, 3)), 0, 500);
        if ($latestEvent) {
            $device->last_event_at = $latestEvent;
        }
        $device->save();

        return $result;
    }

    protected function mapAttendanceStatus(?string $status): string
    {
        $status = strtolower(trim((string) $status));
        // Hikvision values vary by firmware: checkIn / checkOut / other / undefined
        if (in_array($status, ['checkin', 'check_in', 'in', '1'], true)) {
            return 'in';
        }
        if (in_array($status, ['checkout', 'check_out', 'out', '2'], true)) {
            return 'out';
        }

        return 'auto';
    }
}
