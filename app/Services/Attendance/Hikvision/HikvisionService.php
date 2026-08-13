<?php

namespace App\Services\Attendance\Hikvision;

use App\Models\AttendanceClockDevice;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionEmployeeMapping;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * High-level Hikvision device management and synchronization.
 */
class HikvisionService
{
    public function __construct(
        protected HikvisionAttendanceSyncService $attendanceSync,
    ) {
    }

    public function client(AttendanceClockDevice $device): HikvisionIsapiClient
    {
        if ($device->provider !== 'hikvision' || ! filled($device->host)) {
            throw new RuntimeException('This device is not configured as a Hikvision terminal.');
        }
        if (! $device->has_password) {
            throw new RuntimeException('Hikvision device password is not configured.');
        }

        return new HikvisionIsapiClient($device);
    }

    /**
     * @return array{online: bool, device_info: array, capabilities: array, error?: string}
     */
    public function connect(AttendanceClockDevice $device, bool $refreshCapabilities = true): array
    {
        try {
            $client = $this->client($device);
            $info = $client->getDeviceInfo();
            $device->device_info_json = $info;
            $device->last_communication_at = AppTimezone::now();
            $device->last_sync_error = null;

            $capabilities = $device->capabilities_json ?? [];
            if ($refreshCapabilities) {
                $capabilities = $client->discoverCapabilities();
                $device->capabilities_json = $capabilities;
                $device->capabilities_fetched_at = AppTimezone::now();
            }

            $device->save();

            return [
                'online' => true,
                'device_info' => $info,
                'capabilities' => $capabilities,
            ];
        } catch (\Throwable $e) {
            $device->last_sync_error = mb_substr($e->getMessage(), 0, 500);
            $device->save();

            return [
                'online' => false,
                'device_info' => $device->device_info_json ?? [],
                'capabilities' => $device->capabilities_json ?? [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(AttendanceClockDevice $device): array
    {
        $caps = $device->capabilities_json ?? [];
        $counts = [
            'users' => null,
            'cards' => null,
            'events_today' => null,
        ];

        try {
            $client = $this->client($device);
            if ($caps['features']['users'] ?? false) {
                $counts['users'] = $client->getUserCount();
            }
            if ($caps['features']['cards'] ?? false) {
                $counts['cards'] = $client->getCardCount();
            }
            if ($caps['features']['events'] ?? false) {
                $today = AppTimezone::now();
                $counts['events_today'] = $client->countEvents([
                    'major' => 0,
                    'minor' => 0,
                    'startTime' => $today->copy()->startOfDay()->format('Y-m-d\TH:i:sP'),
                    'endTime' => $today->copy()->endOfDay()->format('Y-m-d\TH:i:sP'),
                    'eventAttribute' => 'attendance',
                ]);
            }
            $device->last_communication_at = AppTimezone::now();
            $device->save();
        } catch (\Throwable $e) {
            Log::debug('Hikvision overview counts failed', ['device' => $device->id, 'error' => $e->getMessage()]);
        }

        $orgId = (int) $device->organization_id;
        $mapped = HikvisionEmployeeMapping::query()
            ->where('attendance_clock_device_id', $device->id)
            ->count();
        $centrixEmployees = Employee::query()->where('organization_id', $orgId)->where('is_active', true)->count();

        return [
            'device' => $device,
            'online' => filled($device->last_communication_at)
                && $device->last_communication_at->gt(AppTimezone::now()->subMinutes(30)),
            'counts' => $counts,
            'sync' => [
                'centrix_employees' => $centrixEmployees,
                'device_users' => $counts['users'],
                'mapped' => $mapped,
                'unmapped_device_users' => $counts['users'] !== null
                    ? max(0, $counts['users'] - $mapped)
                    : null,
                'last_synced_at' => optional($device->last_synced_at)?->toIso8601String(),
                'last_event_at' => optional($device->last_event_at)?->toIso8601String(),
                'last_event_serial' => $device->last_event_serial,
                'last_error' => $device->last_sync_error,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $cond
     */
    public function searchUsers(AttendanceClockDevice $device, array $cond = []): array
    {
        $this->assertFeature($device, 'users');

        return $this->client($device)->searchUsers($cond);
    }

    /**
     * @param  array<string, mixed>  $userInfo
     */
    public function createUser(AttendanceClockDevice $device, array $userInfo): array
    {
        $this->assertFeature($device, 'users');

        return $this->client($device)->createUser($userInfo);
    }

    /**
     * @param  list<string>  $employeeNos
     */
    public function deleteUsers(AttendanceClockDevice $device, array $employeeNos): array
    {
        $this->assertFeature($device, 'users');

        return $this->client($device)->deleteUsers($employeeNos);
    }

    /**
     * @param  array<string, mixed>  $cond
     */
    public function searchEvents(AttendanceClockDevice $device, array $cond): array
    {
        $this->assertFeature($device, 'events');
        $from = Carbon::parse($cond['startTime'] ?? AppTimezone::now()->subDay());
        $to = Carbon::parse($cond['endTime'] ?? AppTimezone::now());
        $max = (int) ($cond['maxResults'] ?? 500);
        $caps = $device->capabilities_json ?? [];

        return [
            'events' => $this->client($device)->fetchAccessEvents($from, $to, $max, $caps),
            'total' => $this->client($device)->countEvents(array_filter([
                'major' => $cond['major'] ?? 0,
                'minor' => $cond['minor'] ?? 0,
                'startTime' => $from->format('Y-m-d\TH:i:sP'),
                'endTime' => $to->format('Y-m-d\TH:i:sP'),
                'eventAttribute' => $cond['eventAttribute'] ?? 'attendance',
            ])),
        ];
    }

    /**
     * Sync Centrix employees → Hikvision device (create/update by employee_code).
     *
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function syncEmployeesToDevice(AttendanceClockDevice $device): array
    {
        $this->assertFeature($device, 'users');
        $client = $this->client($device);
        $orgId = (int) $device->organization_id;

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        $employees = Employee::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereNotNull('employee_code')
            ->where('employee_code', '!=', '')
            ->orderBy('id')
            ->get();

        $existing = [];
        $search = $client->searchUsers(['maxResults' => 100]);
        foreach ($search['users'] as $row) {
            $no = (string) ($row['employeeNo'] ?? $row['EmployeeNo'] ?? '');
            if ($no !== '') {
                $existing[$no] = true;
            }
        }

        foreach ($employees as $employee) {
            $employeeNo = trim((string) $employee->employee_code);
            if ($employeeNo === '') {
                $result['skipped']++;

                continue;
            }

            $name = trim((string) ($employee->full_name ?? $employee->first_name.' '.$employee->last_name));
            $payload = [
                'employeeNo' => $employeeNo,
                'name' => $name !== '' ? $name : $employeeNo,
                'userType' => 'normal',
                'Valid' => [
                    'enable' => true,
                    'beginTime' => AppTimezone::now()->startOfYear()->format('Y-m-d\TH:i:s'),
                    'endTime' => '2037-12-31T23:59:59',
                ],
            ];

            try {
                if (isset($existing[$employeeNo])) {
                    $client->setupUser($payload);
                    $result['updated']++;
                } else {
                    $client->createUser($payload);
                    $result['created']++;
                }

                HikvisionEmployeeMapping::query()->updateOrCreate(
                    [
                        'attendance_clock_device_id' => $device->id,
                        'employee_id' => $employee->id,
                    ],
                    [
                        'organization_id' => $orgId,
                        'hikvision_employee_no' => $employeeNo,
                        'sync_status' => 'synced',
                        'last_synced_at' => AppTimezone::now(),
                    ]
                );
            } catch (\Throwable $e) {
                $result['errors'][] = "{$employeeNo}: {$e->getMessage()}";
            }
        }

        $device->last_synced_at = AppTimezone::now();
        $device->save();

        return $result;
    }

    /**
     * List device users not mapped to Centrix employees.
     *
     * @return array{device_users: list<array>, unmapped: list<array>}
     */
    public function syncEmployeesFromDevice(AttendanceClockDevice $device): array
    {
        $this->assertFeature($device, 'users');
        $search = $this->client($device)->searchUsers(['maxResults' => 200]);
        $orgId = (int) $device->organization_id;

        $mappedNos = HikvisionEmployeeMapping::query()
            ->where('attendance_clock_device_id', $device->id)
            ->pluck('hikvision_employee_no')
            ->map(fn ($v) => (string) $v)
            ->all();

        $centrixCodes = Employee::query()
            ->where('organization_id', $orgId)
            ->pluck('employee_code')
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->all();
        $centrixSet = array_fill_keys($centrixCodes, true);

        $unmapped = [];
        foreach ($search['users'] as $row) {
            $no = (string) ($row['employeeNo'] ?? $row['EmployeeNo'] ?? '');
            if ($no === '') {
                continue;
            }
            if (isset($centrixSet[$no]) || in_array($no, $mappedNos, true)) {
                continue;
            }
            $unmapped[] = $row;
        }

        return [
            'device_users' => $search['users'],
            'unmapped' => $unmapped,
        ];
    }

    /**
     * Map a device employee number to a Centrix employee.
     */
    public function mapEmployee(
        AttendanceClockDevice $device,
        string $hikvisionEmployeeNo,
        int $employeeId,
    ): HikvisionEmployeeMapping {
        $employee = Employee::query()->findOrFail($employeeId);
        if ((int) $employee->organization_id !== (int) $device->organization_id) {
            throw ValidationException::withMessages([
                'employee_id' => 'Employee must belong to the same organization as the device.',
            ]);
        }

        return HikvisionEmployeeMapping::query()->updateOrCreate(
            [
                'attendance_clock_device_id' => $device->id,
                'employee_id' => $employee->id,
            ],
            [
                'organization_id' => $device->organization_id,
                'hikvision_employee_no' => $hikvisionEmployeeNo,
                'sync_status' => 'mapped',
                'last_synced_at' => AppTimezone::now(),
            ]
        );
    }

    /**
     * Pull events, store raw rows (idempotent), then apply HR attendance rules.
     *
     * @return array{pulled: int, stored: int, applied: int, skipped: int, errors: list<string>}
     */
    public function syncAttendance(AttendanceClockDevice $device, ?Carbon $from = null, ?Carbon $to = null): array
    {
        return $this->attendanceSync->syncDevice($device, $from, $to);
    }

    /**
     * @return array{events: \Illuminate\Contracts\Pagination\LengthAwarePaginator}
     */
    public function listStoredEvents(AttendanceClockDevice $device, array $filters = [])
    {
        $query = HikvisionAccessEvent::query()
            ->where('attendance_clock_device_id', $device->id)
            ->orderByDesc('event_time');

        if (! empty($filters['employee_no'])) {
            $query->where('employee_no', $filters['employee_no']);
        }
        if (! empty($filters['from'])) {
            $query->where('event_time', '>=', Carbon::parse($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->where('event_time', '<=', Carbon::parse($filters['to']));
        }

        $perPage = min((int) ($filters['per_page'] ?? 50), 200);

        return ['events' => $query->paginate($perPage)];
    }

    public static function buildEventKey(int $deviceId, array $event): string
    {
        $serial = trim((string) ($event['serial_no'] ?? data_get($event, 'raw.serialNo') ?? ''));
        if ($serial !== '') {
            return "d{$deviceId}:s{$serial}";
        }

        $fingerprint = implode('|', [
            $deviceId,
            $event['employee_no'] ?? '',
            $event['punched_at'] ?? '',
            (string) ($event['major'] ?? ''),
            (string) ($event['minor'] ?? ''),
            $event['verification_method'] ?? '',
            $event['attendance_status'] ?? '',
        ]);

        return 'd'.$deviceId.':h'.substr(sha1($fingerprint), 0, 40);
    }

    protected function assertFeature(AttendanceClockDevice $device, string $feature): void
    {
        $caps = $device->capabilities_json ?? [];
        if (! ($caps['features'][$feature] ?? false)) {
            $label = match ($feature) {
                'users' => 'Person / employee management',
                'cards' => 'Card management',
                'fingerprints' => 'Fingerprint management',
                'events' => 'Access / attendance events',
                default => ucfirst($feature),
            };
            throw new RuntimeException("{$label} is not supported by this terminal.");
        }
    }
}
