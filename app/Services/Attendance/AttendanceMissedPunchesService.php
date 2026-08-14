<?php

namespace App\Services\Attendance;

use App\Models\AttendanceClockDevice;
use App\Models\EmployeeClockSession;
use App\Models\HikvisionAccessEvent;
use App\Services\Attendance\Hikvision\HikvisionEventNormalizer;
use App\Services\Attendance\Hikvision\HikvisionService;
use App\Support\AppTimezone;
use Carbon\Carbon;

class AttendanceMissedPunchesService
{
    public function __construct(
        protected HikvisionService $hikvision,
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

        $openSessions = EmployeeClockSession::query()
            ->with('employee:id,full_name,first_name,last_name,employee_code')
            ->where('organization_id', $organizationId)
            ->whereNull('clock_out_at')
            ->whereIn('source', ['clock_device', 'company_mobile'])
            ->where(function ($q) use ($todayStart, $staleCutoff) {
                $q->where('clock_in_at', '<', $todayStart)
                    ->orWhere('clock_in_at', '<=', $staleCutoff);
            })
            ->orderBy('clock_in_at')
            ->limit(200)
            ->get();

        $missingOut = [];
        foreach ($openSessions as $session) {
            $in = $session->clock_in_at
                ? Carbon::parse($session->clock_in_at)->timezone(AppTimezone::name())
                : null;
            $missingOut[] = [
                'id' => $session->id,
                'employee_id' => $session->employee_id,
                'employee_name' => $session->employee?->full_name
                    ?: trim(($session->employee?->first_name ?? '').' '.($session->employee?->last_name ?? '')),
                'employee_code' => $session->employee?->employee_code,
                'source' => $session->source,
                'device_identifier' => $session->device_identifier,
                'clock_in_at' => $in?->format('Y-m-d H:i:s'),
                'hours_open' => $in ? round($in->diffInMinutes(AppTimezone::now()) / 60, 1) : null,
            ];
        }

        return [
            'unapplied_terminal_punches' => $unapplied,
            'duplicate_punches' => $duplicates,
            'missing_clock_out' => $missingOut,
            'counts' => [
                'unapplied_terminal_punches' => count($unapplied),
                'duplicate_punches' => count($duplicates),
                'missing_clock_out' => count($missingOut),
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
}
