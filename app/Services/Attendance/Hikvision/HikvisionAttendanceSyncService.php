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
        if ($from === null) {
            $cursor = $device->last_event_at ? Carbon::parse($device->last_event_at) : null;
            if ($cursor && $cursor->gt($to)) {
                $cursor = $to->copy();
            }
            $from = $cursor
                ? $cursor->copy()->subHours(36)
                : $to->copy()->subDays(7);
        }
        if ($from->gt($to)) {
            $from = $to->copy()->subDays(2);
        }

        $result = [
            'pulled' => 0,
            'stored' => 0,
            'applied' => 0,
            'skipped' => 0,
            'duplicates' => 0,
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
            $retryResult = $this->reprocessPendingEvents($device);
            $device->last_synced_at = AppTimezone::now();
            $device->last_sync_error = mb_substr($e->getMessage(), 0, 500);
            $device->save();

            return $this->mergeProcessResults(
                array_merge($result, ['errors' => [$e->getMessage()]]),
                ['stored' => 0, 'applied' => 0, 'skipped' => 0, 'retried' => 0, 'errors' => []],
                $retryResult,
            );
        }

        $result['pulled'] = count($events);
        // Retry stuck punches first (mapping may have been fixed), then apply the fresh pull.
        $retryResult = $this->reprocessPendingEvents($device);
        $processResult = $this->processEvents($device, $events);

        return $this->mergeProcessResults($result, $processResult, $retryResult);
    }

    /**
     * Pull and apply punches for every active Hikvision clock in the organization.
     *
     * @return array{
     *   devices: int,
     *   pulled: int,
     *   stored: int,
     *   applied: int,
     *   skipped: int,
     *   retried: int,
     *   offline: int,
     *   errors: list<string>
     * }
     */
    public function syncOrganization(int $organizationId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $devices = AttendanceClockDevice::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('provider', 'hikvision')
            ->whereNotNull('host')
            ->where('host', '!=', '')
            ->orderBy('id')
            ->get();

        $summary = [
            'devices' => $devices->count(),
            'pulled' => 0,
            'stored' => 0,
            'applied' => 0,
            'skipped' => 0,
            'duplicates' => 0,
            'retried' => 0,
            'offline' => 0,
            'errors' => [],
        ];

        foreach ($devices as $device) {
            $result = $this->syncDevice($device, $from, $to);
            $summary['pulled'] += (int) ($result['pulled'] ?? 0);
            $summary['stored'] += (int) ($result['stored'] ?? 0);
            $summary['applied'] += (int) ($result['applied'] ?? 0);
            $summary['skipped'] += (int) ($result['skipped'] ?? 0);
            $summary['duplicates'] += (int) ($result['duplicates'] ?? 0);
            $summary['retried'] += (int) ($result['retried'] ?? 0);
            $errors = $result['errors'] ?? [];
            foreach ($errors as $error) {
                $label = trim((string) ($device->device_name ?: $device->device_no));
                $summary['errors'][] = $label !== '' ? "{$label}: {$error}" : (string) $error;
                if (str_contains((string) $error, 'CentrixAttendanceAgent')) {
                    $summary['offline']++;
                }
            }
        }

        $summary['errors'] = array_slice($summary['errors'], 0, 20);

        return $summary;
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
            'duplicates' => 0,
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
            'duplicates' => 0,
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
        $result['duplicates'] = (int) ($applied['duplicates'] ?? 0);
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
            'duplicates' => 0,
            'errors' => [],
        ];

        $latestEvent = $device->last_event_at
            ? Carbon::parse($device->last_event_at)
            : null;
        $latestSerial = $device->last_event_serial;

        $mappingCache = [];

        foreach ($events as $event) {
            $event = HikvisionEventNormalizer::normalizeIncoming($event);
            $eventKey = isset($event['_event_key'])
                ? (string) $event['_event_key']
                : HikvisionService::buildEventKey((int) $device->id, $event);

            $punchedAt = AppTimezone::fromDeviceWallClock($event['punched_at'] ?? null);
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
                $this->backfillStoredEventFields($stored, $event);
            }

            if ($stored->processed_at !== null) {
                continue;
            }

            if ($this->sameHourAlreadyApplied($device, (string) ($event['employee_no'] ?? $stored->employee_no ?? ''), $punchedAt, (int) $stored->id)) {
                $this->markEventAsDuplicatePunch($stored);
                $result['skipped']++;
                $result['duplicates'] = ($result['duplicates'] ?? 0) + 1;

                continue;
            }

            $employeeNo = (string) ($event['employee_no'] ?? $stored->employee_no ?? '');
            $employeeId = $this->resolveMappedEmployeeId(
                $device,
                $employeeNo,
                $mappingCache,
                (string) ($event['employee_name'] ?? $stored->employee_name ?? ''),
            );
            $direction = 'auto';

            try {
                $punch = $this->applyPunch($device, $employeeNo, $employeeId, $punchedAt, $direction);
                $sessionId = $punch['session']->id ?? null;
                if (($punch['action'] ?? '') === 'ignored') {
                    $this->markEventAsDuplicatePunch($stored, $sessionId);
                    $result['skipped']++;
                    $result['duplicates'] = ($result['duplicates'] ?? 0) + 1;
                    $this->markSameHourDuplicatesProcessed(
                        $device,
                        $employeeNo,
                        $punchedAt,
                        (int) $stored->id,
                        $sessionId,
                    );

                    continue;
                }

                $stored->processed_at = AppTimezone::now();
                $stored->process_error = null;
                $stored->clock_session_id = $sessionId;
                $stored->save();
                $result['applied']++;
                $this->markSameHourDuplicatesProcessed(
                    $device,
                    $employeeNo,
                    $punchedAt,
                    (int) $stored->id,
                    $sessionId,
                );
            } catch (ValidationException $e) {
                $msg = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                if (is_string($msg) && str_contains(strtolower($msg), 'already has an open')) {
                    $this->markEventAsDuplicatePunch($stored);
                    $result['skipped']++;
                    $result['duplicates'] = ($result['duplicates'] ?? 0) + 1;
                } else {
                    $stored->process_error = mb_substr((string) $msg, 0, 500);
                    $stored->save();
                    $result['errors'][] = "{$employeeNo} @ {$punchedAt->toIso8601String()}: {$msg}";
                }
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

    protected function sameHourAlreadyApplied(
        AttendanceClockDevice $device,
        string $employeeNo,
        Carbon $punchedAt,
        int $exceptEventId,
    ): bool {
        [$start, $end] = $this->hourBounds($punchedAt);

        return HikvisionAccessEvent::query()
            ->where('attendance_clock_device_id', $device->id)
            ->where('employee_no', $employeeNo)
            ->whereNotNull('processed_at')
            ->whereNotNull('clock_session_id')
            ->where('id', '!=', $exceptEventId)
            ->whereBetween('event_time', [$start, $end])
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('employee_clock_sessions')
                    ->whereColumn('employee_clock_sessions.id', 'hikvision_access_events.clock_session_id');
            })
            ->exists();
    }

    protected function markSameHourDuplicatesProcessed(
        AttendanceClockDevice $device,
        string $employeeNo,
        Carbon $punchedAt,
        int $exceptEventId,
        mixed $sessionId,
    ): void {
        [$start, $end] = $this->hourBounds($punchedAt);

        HikvisionAccessEvent::query()
            ->where('attendance_clock_device_id', $device->id)
            ->where('employee_no', $employeeNo)
            ->whereNull('processed_at')
            ->where('id', '!=', $exceptEventId)
            ->whereBetween('event_time', [$start, $end])
            ->update([
                'processed_at' => AppTimezone::now(),
                'process_error' => HikvisionAccessEvent::DUPLICATE_PUNCH,
                'clock_session_id' => $sessionId,
            ]);
    }

    protected function markEventAsDuplicatePunch(HikvisionAccessEvent $stored, mixed $sessionId = null): void
    {
        $stored->processed_at = AppTimezone::now();
        $stored->process_error = HikvisionAccessEvent::DUPLICATE_PUNCH;
        if ($sessionId) {
            $stored->clock_session_id = $sessionId;
        }
        $stored->save();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function hourBounds(Carbon $at): array
    {
        $local = $at->copy()->timezone(AppTimezone::name());
        $start = $local->copy()->startOfHour();
        $end = $local->copy()->endOfHour();

        return [
            Carbon::parse($start->format('Y-m-d H:i:s'), AppTimezone::name()),
            Carbon::parse($end->format('Y-m-d H:i:s'), AppTimezone::name()),
        ];
    }

    /**
     * @param  array<string, int|null>  $cache
     */
    protected function resolveMappedEmployeeId(
        AttendanceClockDevice $device,
        string $employeeNo,
        array &$cache,
        string $employeeName = '',
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
            ->whereIn('hikvision_employee_no', HikvisionService::employeeNoLookupVariants($employeeNo))
            ->first();

        $employeeId = $mapping?->employee_id ? (int) $mapping->employee_id : null;
        if (! $employeeId) {
            $employee = HikvisionService::findUniqueEmployeeForTerminalNo(
                (int) $device->organization_id,
                $employeeNo,
            );
            if (! $employee && $employeeName !== '') {
                $employee = HikvisionService::findUniqueEmployeeByName(
                    (int) $device->organization_id,
                    $employeeName,
                );
            }
            if ($employee) {
                $employeeId = (int) $employee->id;
                $this->persistAutoMapping($device, $employeeNo, $employeeId);
            }
        }

        $cache[$key] = $employeeId;

        return $cache[$key];
    }

    protected function persistAutoMapping(AttendanceClockDevice $device, string $employeeNo, int $employeeId): void
    {
        HikvisionEmployeeMapping::query()
            ->where('attendance_clock_device_id', $device->id)
            ->where('employee_id', $employeeId)
            ->where('hikvision_employee_no', '!=', $employeeNo)
            ->delete();
        HikvisionEmployeeMapping::query()->updateOrCreate(
            [
                'attendance_clock_device_id' => $device->id,
                'hikvision_employee_no' => $employeeNo,
            ],
            [
                'organization_id' => $device->organization_id,
                'employee_id' => $employeeId,
                'sync_status' => 'mapped',
                'last_synced_at' => AppTimezone::now(),
            ]
        );
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

        return $this->punchService->punch($payload);
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
        $base['duplicates'] = (int) ($base['duplicates'] ?? 0)
            + (int) ($process['duplicates'] ?? 0)
            + (int) ($retry['duplicates'] ?? 0);
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

    /**
     * @param  array<string, mixed>  $event
     */
    protected function backfillStoredEventFields(HikvisionAccessEvent $stored, array $event): void
    {
        $dirty = false;
        foreach (['attendance_status', 'verification_method', 'employee_name', 'card_no', 'serial_no'] as $field) {
            $incoming = HikvisionEventNormalizer::usableString($event[$field] ?? null);
            $current = HikvisionEventNormalizer::usableString($stored->{$field});
            if ($current === null && $incoming !== null) {
                $stored->{$field} = $incoming;
                $dirty = true;
            }
        }
        foreach (['major', 'minor'] as $field) {
            if ($stored->{$field} === null && isset($event[$field]) && $event[$field] !== null) {
                $stored->{$field} = $event[$field];
                $dirty = true;
            }
        }
        if ($dirty) {
            $stored->save();
        }
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
