<?php

namespace App\Services\Attendance;

use App\Models\AttendanceClockDevice;
use App\Models\Employee;
use App\Models\EmployeeClockSession;
use App\Models\HikvisionAccessEvent;
use App\Services\Attendance\Hikvision\HikvisionEventNormalizer;
use App\Services\Attendance\Hikvision\HikvisionService;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class AttendanceMissedPunchesService
{
    public function __construct(
        protected HikvisionService $hikvision,
        protected AttendanceClockPunchService $punchService,
        protected ForgottenClockOutService $forgottenClockOuts,
    ) {
    }

    /**
     * @return array{
     *     unapplied_terminal_punches: list<array<string, mixed>>,
     *     duplicate_punches: list<array<string, mixed>>,
     *     missing_clock_out: list<array<string, mixed>>,
     *     counts: array{unapplied_terminal_punches: int, duplicate_punches: int, missing_clock_out: int}
     * }
     */
    public function listForOrganization(int $organizationId): array
    {
        $events = HikvisionAccessEvent::query()
            ->with(['device:id,device_no,location,provider'])
            ->where('organization_id', $organizationId)
            ->whereNull('processed_at')
            ->orderByDesc('event_time')
            ->limit(400)
            ->get();

        $unapplied = [];
        $duplicates = [];
        $seenHour = [];
        foreach ($events as $row) {
            HikvisionEventNormalizer::present($row);
            $at = AppTimezone::fromDeviceWallClock($row->event_time) ?? AppTimezone::normalize($row->event_time);
            $hourKey = implode('|', [
                $row->attendance_clock_device_id,
                (string) $row->employee_no,
                $at?->timezone(AppTimezone::name())->format('Y-m-d H') ?? (string) $row->id,
            ]);
            $payload = $this->presentEvent($row);
            if (isset($seenHour[$hourKey])) {
                $payload['process_error'] = 'Extra scan in the same hour as another punch that still needs mapping.';
                $duplicates[] = $payload;

                continue;
            }
            $seenHour[$hourKey] = true;
            $unapplied[] = $payload;
        }

        $outsideWindow = HikvisionAccessEvent::query()
            ->with(['device:id,device_no,location,provider'])
            ->where('organization_id', $organizationId)
            ->where('process_error', HikvisionAccessEvent::OUTSIDE_WINDOW)
            ->orderByDesc('event_time')
            ->limit(200)
            ->get();

        foreach ($outsideWindow as $row) {
            HikvisionEventNormalizer::present($row);
            $payload = $this->presentEvent($row);
            $payload['process_error'] = 'Punch was outside lunch or clock-out windows. Attendance was not applied.';
            $unapplied[] = $payload;
        }

        usort($unapplied, function (array $a, array $b) {
            return strcmp((string) ($b['event_time'] ?? ''), (string) ($a['event_time'] ?? ''));
        });
        $unapplied = array_slice($unapplied, 0, 400);

        $loggedDuplicates = HikvisionAccessEvent::query()
            ->with(['device:id,device_no,location,provider'])
            ->where('organization_id', $organizationId)
            ->where('process_error', HikvisionAccessEvent::DUPLICATE_PUNCH)
            ->orderByDesc('event_time')
            ->limit(200)
            ->get();

        foreach ($loggedDuplicates as $row) {
            HikvisionEventNormalizer::present($row);
            $payload = $this->presentEvent($row);
            $payload['process_error'] = 'Extra scan in the same hour. Attendance already recorded from the first punch.';
            $duplicates[] = $payload;
        }

        usort($duplicates, function (array $a, array $b) {
            return strcmp((string) ($b['event_time'] ?? ''), (string) ($a['event_time'] ?? ''));
        });
        $duplicates = array_slice($duplicates, 0, 200);

        $todayStart = AppTimezone::parseDateStart(AppTimezone::todayDateString());
        $staleCutoff = AppTimezone::now()->subHours(12);

        $openOrFlagged = EmployeeClockSession::query()
            ->with('employee.shift')
            ->where('organization_id', $organizationId)
            ->whereIn('source', ['clock_device', 'company_mobile'])
            ->where(function ($q) use ($todayStart, $staleCutoff) {
                $q->where('needs_reconciliation', true)
                    ->orWhere(function ($inner) use ($todayStart, $staleCutoff) {
                        $inner->whereNull('clock_out_at')
                            ->where(function ($open) use ($todayStart, $staleCutoff) {
                                $open->where('clock_in_at', '<', $todayStart->format('Y-m-d H:i:s'))
                                    ->orWhere('clock_in_at', '<=', $staleCutoff->format('Y-m-d H:i:s'));
                            });
                    });
            })
            ->orderBy('clock_in_at')
            ->limit(200)
            ->get();

        $missingOut = [];
        foreach ($openOrFlagged as $session) {
            $in = $session->clock_in_at
                ? Carbon::parse($session->clock_in_at)->timezone(AppTimezone::name())
                : null;
            $out = $session->clock_out_at
                ? Carbon::parse($session->clock_out_at)->timezone(AppTimezone::name())
                : null;
            $suggested = $this->forgottenClockOuts->suggestedCloseAt($session);
            $missingOut[] = [
                'id' => $session->id,
                'employee_id' => $session->employee_id,
                'employee_name' => $session->employee?->full_name
                    ?: trim(($session->employee?->first_name ?? '').' '.($session->employee?->last_name ?? '')),
                'employee_code' => $session->employee?->employee_code,
                'source' => $session->source,
                'device_identifier' => $session->device_identifier,
                'clock_in_at' => $in?->format('Y-m-d H:i:s'),
                'clock_out_at' => $out?->format('Y-m-d H:i:s'),
                'suggested_clock_out_at' => $suggested?->format('Y-m-d H:i:s'),
                'clock_out_kind' => $session->clock_out_kind,
                'needs_reconciliation' => (bool) $session->needs_reconciliation,
                'auto_closed' => $session->clock_out_kind === EmployeeClockSession::CLOCK_OUT_KIND_AUTO_FORGOTTEN,
                'hours_open' => $in ? round($in->diffInMinutes($out ?? AppTimezone::now()) / 60, 1) : null,
            ];
        }

        $withoutShiftCount = Employee::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('shift_id')->orWhere('shift_id', 0);
            })
            ->count();

        $missingShift = Employee::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('shift_id')->orWhere('shift_id', 0);
            })
            ->orderBy('full_name')
            ->limit(50)
            ->get(['id', 'full_name', 'first_name', 'last_name', 'employee_code', 'shift_id']);

        $withoutShift = [];
        foreach ($missingShift as $employee) {
            $withoutShift[] = [
                'id' => $employee->id,
                'employee_name' => $employee->full_name
                    ?: trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
                'employee_code' => $employee->employee_code,
            ];
        }

        return [
            'unapplied_terminal_punches' => $unapplied,
            'duplicate_punches' => $duplicates,
            'missing_clock_out' => $missingOut,
            'employees_without_shift' => $withoutShift,
            'counts' => [
                'unapplied_terminal_punches' => count($unapplied),
                'duplicate_punches' => count($duplicates),
                'missing_clock_out' => count($missingOut),
                'employees_without_shift' => $withoutShiftCount,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentEvent(HikvisionAccessEvent $row): array
    {
        return [
            'id' => $row->id,
            'event_key' => $row->event_key,
            'event_time' => $row->event_time,
            'event_time_local' => $row->event_time_local ?? null,
            'employee_no' => $row->employee_no,
            'employee_name' => $row->employee_name,
            'attendance_status' => $row->attendance_status,
            'verification_method' => $row->verification_method,
            'process_error' => $row->process_error,
            'device_id' => $row->attendance_clock_device_id,
            'device_no' => $row->device?->device_no,
            'device_location' => $row->device?->location,
        ];
    }

    /**
     * @return array{devices: int, stored: int, applied: int, skipped: int, retried: int, errors: list<string>}
     */
    public function retryUnapplied(int $organizationId): array
    {
        $devices = AttendanceClockDevice::query()
            ->where('organization_id', $organizationId)
            ->where('provider', 'hikvision')
            ->where('is_active', true)
            ->get();

        $merged = [
            'devices' => $devices->count(),
            'stored' => 0,
            'applied' => 0,
            'skipped' => 0,
            'retried' => 0,
            'errors' => [],
        ];

        foreach ($devices as $device) {
            $result = $this->hikvision->reprocessPendingAttendance($device);
            $merged['stored'] += (int) ($result['stored'] ?? 0);
            $merged['applied'] += (int) ($result['applied'] ?? 0);
            $merged['skipped'] += (int) ($result['skipped'] ?? 0);
            $merged['retried'] += (int) ($result['retried'] ?? 0);
            foreach ($result['errors'] ?? [] as $error) {
                $merged['errors'][] = $error;
            }
        }
        $merged['errors'] = array_slice($merged['errors'], 0, 20);

        return $merged;
    }

    /**
     * @return array{dismissed: int}
     */
    public function dismissDuplicatePunches(int $organizationId, ?int $eventId = null): array
    {
        $query = HikvisionAccessEvent::query()
            ->where('organization_id', $organizationId)
            ->where('process_error', HikvisionAccessEvent::DUPLICATE_PUNCH);
        if ($eventId) {
            $query->where('id', $eventId);
        }

        $dismissed = $query->update([
            'process_error' => HikvisionAccessEvent::DUPLICATE_PUNCH_DISMISSED,
        ]);

        return ['dismissed' => $dismissed];
    }

    /**
     * @return array{action: string, session: mixed, attendance?: mixed}
     */
    public function closeMissingClockOut(
        int $organizationId,
        int $sessionId,
        mixed $punchedAt = null,
        mixed $clockInAt = null,
        bool $confirm = false,
    ): array {
        $session = EmployeeClockSession::query()
            ->where('organization_id', $organizationId)
            ->where('id', $sessionId)
            ->first();
        if (! $session) {
            throw ValidationException::withMessages([
                'session_id' => 'Clock session not found.',
            ]);
        }

        $payload = [
            'confirm_reconciliation' => $confirm || $punchedAt !== null || $clockInAt !== null,
        ];
        if ($clockInAt !== null && $clockInAt !== '') {
            $payload['clock_in_at'] = $clockInAt;
        }
        if ($punchedAt !== null && $punchedAt !== '') {
            $payload['clock_out_at'] = $punchedAt;
        } elseif (! $session->clock_out_at) {
            $suggested = $this->forgottenClockOuts->suggestedCloseAt($session);
            if ($suggested) {
                $payload['clock_out_at'] = $suggested->format('Y-m-d H:i:s');
            }
        }

        return $this->punchService->updateSession($organizationId, $sessionId, $payload);
    }

    /**
     * Auto-map device persons then retry pending punches.
     *
     * @return array<string, mixed>
     */
    public function autoMapAndRetry(int $organizationId): array
    {
        $devices = AttendanceClockDevice::query()
            ->where('organization_id', $organizationId)
            ->where('provider', 'hikvision')
            ->where('is_active', true)
            ->get();

        $mapped = 0;
        $errors = [];
        foreach ($devices as $device) {
            try {
                $result = $this->hikvision->autoMapDeviceUsers($device);
                $mapped += (int) ($result['mapped'] ?? 0);
                foreach ($result['errors'] ?? [] as $error) {
                    $errors[] = $error;
                }
            } catch (\Throwable $e) {
                $errors[] = ($device->device_no ?: 'device').': '.$e->getMessage();
            }
        }

        $retry = $this->retryUnapplied($organizationId);
        $retry['mapped'] = $mapped;
        $retry['errors'] = array_slice(array_merge($errors, $retry['errors'] ?? []), 0, 20);

        return $retry;
    }
}
