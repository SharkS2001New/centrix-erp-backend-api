<?php

namespace App\Services\Attendance\Hikvision;

use App\Models\AttendanceClockDevice;
use App\Models\HikvisionAccessEvent;
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
     * @return array{pulled: int, stored: int, applied: int, skipped: int, errors: list<string>}
     */
    public function syncDevice(AttendanceClockDevice $device, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = $to ?? AppTimezone::now();
        $from = $from ?? (
            $device->last_event_at
                ? Carbon::parse($device->last_event_at)->subMinute()
                : AppTimezone::now()->subDays(7)
        );

        $result = [
            'pulled' => 0,
            'stored' => 0,
            'applied' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            $client = new HikvisionIsapiClient($device);
            $caps = $device->capabilities_json ?? null;
            $events = $client->fetchAccessEvents($from, $to, 1000, is_array($caps) ? $caps : null);
        } catch (\Throwable $e) {
            $device->last_synced_at = AppTimezone::now();
            $device->last_sync_error = mb_substr($e->getMessage(), 0, 500);
            $device->save();

            return array_merge($result, ['errors' => [$e->getMessage()]]);
        }

        $result['pulled'] = count($events);
        $latestEvent = $device->last_event_at
            ? Carbon::parse($device->last_event_at)
            : null;
        $latestSerial = $device->last_event_serial;

        usort($events, static fn ($a, $b) => strcmp($a['punched_at'], $b['punched_at']));

        foreach ($events as $event) {
            $eventKey = HikvisionService::buildEventKey((int) $device->id, $event);

            $stored = HikvisionAccessEvent::query()->firstOrCreate(
                [
                    'attendance_clock_device_id' => $device->id,
                    'event_key' => $eventKey,
                ],
                [
                    'organization_id' => $device->organization_id,
                    'employee_no' => $event['employee_no'],
                    'employee_name' => $event['employee_name'] ?? null,
                    'event_time' => Carbon::parse($event['punched_at']),
                    'major' => $event['major'] ?? null,
                    'minor' => $event['minor'] ?? null,
                    'attendance_status' => $event['attendance_status'] ?? null,
                    'verification_method' => $event['verification_method'] ?? null,
                    'card_no' => $event['card_no'] ?? null,
                    'serial_no' => $event['serial_no'] ?? null,
                    'raw_payload' => $event['raw'] ?? [],
                ]
            );

            if ($stored->wasRecentlyCreated) {
                $result['stored']++;
            } else {
                $result['skipped']++;
            }

            if ($stored->processed_at !== null) {
                continue;
            }

            try {
                $direction = $this->mapAttendanceStatus($event['attendance_status'] ?? null);
                $punch = $this->punchService->punch([
                    'organization_id' => (int) $device->organization_id,
                    'employee_code' => $event['employee_no'],
                    'device_no' => $device->device_no,
                    'punched_at' => $event['punched_at'],
                    'direction' => $direction,
                ]);
                $stored->processed_at = AppTimezone::now();
                $stored->clock_session_id = $punch['session']->id ?? null;
                $stored->save();
                $result['applied']++;
            } catch (ValidationException $e) {
                $msg = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                $result['errors'][] = "{$event['employee_no']} @ {$event['punched_at']}: {$msg}";
            } catch (\Throwable $e) {
                $result['errors'][] = "{$event['employee_no']} @ {$event['punched_at']}: {$e->getMessage()}";
                Log::warning('Hikvision punch sync failed', [
                    'device_no' => $device->device_no,
                    'error' => $e->getMessage(),
                ]);
            }

            $at = Carbon::parse($event['punched_at']);
            if (! $latestEvent || $at->gt($latestEvent)) {
                $latestEvent = $at;
            }
            if (! empty($event['serial_no'])) {
                $latestSerial = (string) $event['serial_no'];
            }
        }

        $device->last_synced_at = AppTimezone::now();
        $device->last_communication_at = AppTimezone::now();
        $device->last_sync_error = $result['errors'] === []
            ? null
            : mb_substr(implode(' | ', array_slice($result['errors'], 0, 3)), 0, 500);
        if ($latestEvent) {
            $device->last_event_at = $latestEvent;
        }
        if ($latestSerial) {
            $device->last_event_serial = $latestSerial;
        }
        $device->save();

        return $result;
    }

    protected function mapAttendanceStatus(?string $status): string
    {
        $status = strtolower(trim((string) $status));
        if (in_array($status, ['checkin', 'check_in', 'in', '1'], true)) {
            return 'in';
        }
        if (in_array($status, ['checkout', 'check_out', 'out', '2'], true)) {
            return 'out';
        }

        return 'auto';
    }
}
