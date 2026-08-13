<?php

namespace App\Services\Attendance\Hikvision;

use App\Models\AttendanceClockDevice;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionEmployeeMapping;
use App\Services\Attendance\AttendanceClockPunchService;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class HikvisionAttendanceSyncService
{
    public function __construct(
        protected AttendanceClockPunchService $punchService,
        protected HikvisionAgentBridge $agentBridge,
    ) {
    }

    /**
     * Pull events via CentrixAttendanceAgent (when online), store, apply, then retry pending.
     *
     * @return array{pulled: int, stored: int, applied: int, skipped: int, retried: int, errors: list<string>, via_agent: bool}
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
            'retried' => 0,
            'errors' => [],
            'via_agent' => false,
        ];

        try {
            if (! $this->agentBridge->isAgentOnline($device)) {
                throw new \RuntimeException(
                    'CentrixAttendanceAgent is offline. Keep the Windows service running on the office LAN, then Sync attendance again.',
                );
            }

            $client = new HikvisionIsapiClient($device, $this->agentBridge);
            $caps = $device->capabilities_json ?? null;
            $events = $client->fetchAccessEvents($from, $to, 1000, is_array($caps) ? $caps : null);
            $result['via_agent'] = $client->lastRequestViaAgent();
        } catch (\Throwable $e) {
            $device->last_synced_at = AppTimezone::now();
            $device->last_sync_error = mb_substr($e->getMessage(), 0, 500);
            $device->save();

            return array_merge($result, ['errors' => [$e->getMessage()]]);
        }

        $result['pulled'] = count($events);
        // Retry stuck punches first (mapping may have been fixed), then apply the fresh pull.
        $retryResult = $this->reprocessPendingEvents($device);
        $processResult = $this->processEvents($device, $events);

        return $this->mergeProcessResults($result, $processResult, $retryResult);
    }

    /**
     * Store and apply pre-normalized events (from LAN agent or tests).
     * Retries older unprocessed events first (e.g. after HR maps an employee).
     *
     * @param  list<array<string, mixed>>  $events
     * @return array{stored: int, applied: int, skipped: int, retried: int, errors: list<string>}
     */
    public function ingestEvents(AttendanceClockDevice $device, array $events): array
    {
        $incomingKeys = [];
        foreach ($events as $event) {
            $incomingKeys[] = HikvisionService::buildEventKey((int) $device->id, $event);
        }

        $retryResult = $this->reprocessPendingEvents($device, 300, null, $incomingKeys);

        usort($events, static fn ($a, $b) => strcmp(
            (string) ($a['punched_at'] ?? ''),
            (string) ($b['punched_at'] ?? ''),
        ));

        $processResult = $this->processEvents($device, $events);

        return $this->mergeProcessResults([
            'stored' => 0,
            'applied' => 0,
            'skipped' => 0,
            'retried' => 0,
            'errors' => [],
        ], $processResult, $retryResult);
    }

    /**
     * Re-apply stored events that never reached a clock session (e.g. unmapped employee, later mapped).
     *
     * @param  list<string>  $excludeEventKeys
     * @return array{stored: int, applied: int, skipped: int, retried: int, errors: list<string>}
     */
    public function reprocessPendingEvents(
        AttendanceClockDevice $device,
        int $limit = 300,
        ?string $employeeNo = null,
        array $excludeEventKeys = [],
    ): array {
        $result = [
            'stored' => 0,
            'applied' => 0,
            'skipped' => 0,
            'retried' => 0,
            'errors' => [],
        ];

        $query = HikvisionAccessEvent::query()
            ->where('attendance_clock_device_id', $device->id)
            ->whereNull('processed_at')
            ->orderBy('event_time')
            ->orderBy('id')
            ->limit(max(1, min(1000, $limit)));

        if ($employeeNo !== null && $employeeNo !== '') {
            $query->where('employee_no', $employeeNo);
        }
        if ($excludeEventKeys !== []) {
            $query->whereNotIn('event_key', $excludeEventKeys);
        }

        $pending = $query->get();
        if ($pending->isEmpty()) {
            return $result;
        }

        $events = [];
        foreach ($pending as $row) {
            $events[] = [
                'employee_no' => (string) $row->employee_no,
                'employee_name' => $row->employee_name,
                'punched_at' => optional($row->event_time)?->toIso8601String()
                    ?? (string) $row->event_time,
                'attendance_status' => $row->attendance_status,
                'verification_method' => $row->verification_method,
                'card_no' => $row->card_no,
                'serial_no' => $row->serial_no,
                'major' => $row->major,
                'minor' => $row->minor,
                'raw' => is_array($row->raw_payload) ? $row->raw_payload : [],
                '_event_key' => $row->event_key,
            ];
        }

        $applied = $this->processEvents($device, $events, updateDeviceCursor: false);
        $result['applied'] = $applied['applied'];
        $result['retried'] = $applied['applied'];
        $result['skipped'] = $applied['skipped'];
        $result['errors'] = $applied['errors'];

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array{stored: int, applied: int, skipped: int, errors: list<string>}
     */
    protected function processEvents(
        AttendanceClockDevice $device,
        array $events,
        bool $updateDeviceCursor = true,
    ): array {
        $result = [
            'stored' => 0,
            'applied' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $latestEvent = $device->last_event_at
            ? Carbon::parse($device->last_event_at)
            : null;
        $latestSerial = $device->last_event_serial;

        $mappingCache = [];

        foreach ($events as $event) {
            $eventKey = isset($event['_event_key'])
                ? (string) $event['_event_key']
                : HikvisionService::buildEventKey((int) $device->id, $event);

            $punchedAt = AppTimezone::normalize($event['punched_at'] ?? null);
            if ($punchedAt === null) {
                $result['errors'][] = ($event['employee_no'] ?? '?').': invalid punched_at';

                continue;
            }

            $stored = HikvisionAccessEvent::query()->firstOrCreate(
                [
                    'attendance_clock_device_id' => $device->id,
                    'event_key' => $eventKey,
                ],
                [
                    'organization_id' => $device->organization_id,
                    'employee_no' => $event['employee_no'],
                    'employee_name' => $event['employee_name'] ?? null,
                    'event_time' => $punchedAt,
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

            $employeeNo = (string) ($event['employee_no'] ?? $stored->employee_no ?? '');
            $employeeId = $this->resolveMappedEmployeeId($device, $employeeNo, $mappingCache);
            $direction = $this->mapAttendanceStatus($event['attendance_status'] ?? $stored->attendance_status);

            try {
                $punch = $this->applyPunch($device, $employeeNo, $employeeId, $punchedAt, $direction);
                $stored->processed_at = AppTimezone::now();
                $stored->process_error = null;
                $stored->clock_session_id = $punch['session']->id ?? null;
                $stored->save();
                $result['applied']++;
            } catch (ValidationException $e) {
                $msg = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                $stored->process_error = mb_substr((string) $msg, 0, 500);
                $stored->save();
                $result['errors'][] = "{$employeeNo} @ {$punchedAt->toIso8601String()}: {$msg}";
            } catch (\Throwable $e) {
                $stored->process_error = mb_substr($e->getMessage(), 0, 500);
                $stored->save();
                $result['errors'][] = "{$employeeNo} @ {$punchedAt->toIso8601String()}: {$e->getMessage()}";
                Log::warning('Hikvision punch sync failed', [
                    'device_no' => $device->device_no,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($updateDeviceCursor) {
                if (! $latestEvent || $punchedAt->gt($latestEvent)) {
                    $latestEvent = $punchedAt;
                }
                if (! empty($event['serial_no'])) {
                    $latestSerial = (string) $event['serial_no'];
                }
            }
        }

        $device->last_synced_at = AppTimezone::now();
        $device->last_communication_at = AppTimezone::now();
        $device->last_sync_error = $result['errors'] === []
            ? null
            : mb_substr(implode(' | ', array_slice($result['errors'], 0, 3)), 0, 500);
        if ($updateDeviceCursor) {
            if ($latestEvent) {
                $device->last_event_at = $latestEvent;
            }
            if ($latestSerial) {
                $device->last_event_serial = $latestSerial;
            }
        }
        $device->save();

        return $result;
    }

    /**
     * @param  array<string, int|null>  $cache
     */
    protected function resolveMappedEmployeeId(
        AttendanceClockDevice $device,
        string $employeeNo,
        array &$cache,
    ): ?int {
        $key = trim($employeeNo);
        if ($key === '') {
            return null;
        }
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $mapping = HikvisionEmployeeMapping::query()
            ->where('attendance_clock_device_id', $device->id)
            ->where('hikvision_employee_no', $key)
            ->first();

        $cache[$key] = $mapping?->employee_id ? (int) $mapping->employee_id : null;

        return $cache[$key];
    }

    /**
     * @return array{action: string, session: mixed, attendance?: mixed}
     */
    protected function applyPunch(
        AttendanceClockDevice $device,
        string $employeeNo,
        ?int $employeeId,
        Carbon $punchedAt,
        string $direction,
    ): array {
        $payload = [
            'organization_id' => (int) $device->organization_id,
            'device_no' => $device->device_no,
            'punched_at' => $punchedAt,
            'direction' => $direction,
        ];
        if ($employeeId) {
            $payload['employee_id'] = $employeeId;
        } else {
            $payload['employee_code'] = $employeeNo;
        }

        try {
            return $this->punchService->punch($payload);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? $e->getMessage();
            // Many Hikvision firmwares mark every scan as checkIn — treat a second scan as clock-out.
            if (
                $direction === 'in'
                && is_string($msg)
                && str_contains(strtolower($msg), 'already has an open')
            ) {
                $payload['direction'] = 'out';

                return $this->punchService->punch($payload);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $process
     * @param  array<string, mixed>  $retry
     * @return array<string, mixed>
     */
    protected function mergeProcessResults(array $base, array $process, array $retry): array
    {
        $base['stored'] = (int) ($base['stored'] ?? 0) + (int) ($process['stored'] ?? 0);
        $base['applied'] = (int) ($base['applied'] ?? 0)
            + (int) ($process['applied'] ?? 0)
            + (int) ($retry['applied'] ?? 0);
        $base['skipped'] = (int) ($base['skipped'] ?? 0)
            + (int) ($process['skipped'] ?? 0)
            + (int) ($retry['skipped'] ?? 0);
        $base['retried'] = (int) ($base['retried'] ?? 0) + (int) ($retry['retried'] ?? 0);
        $base['errors'] = array_values(array_merge(
            $base['errors'] ?? [],
            $process['errors'] ?? [],
            // Retry errors supersede for the same pending rows; keep a short combined list.
            $retry['errors'] ?? [],
        ));
        $base['errors'] = array_slice($base['errors'], 0, 20);

        return $base;
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
