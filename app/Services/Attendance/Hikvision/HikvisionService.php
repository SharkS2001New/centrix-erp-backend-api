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
        protected HikvisionAgentBridge $agentBridge,
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

        return new HikvisionIsapiClient($device, $this->agentBridge);
    }

    /**
     * ISAPI UserInfo for attendance terminals. Incomplete records appear in the
     * person list but local fingerprint enroll returns “authentication failed”.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function terminalUserInfo(string $employeeNo, string $name, array $overrides = []): array
    {
        $payload = [
            'employeeNo' => $employeeNo,
            'name' => $name !== '' ? $name : $employeeNo,
            'userType' => 'normal',
            'gender' => 'unknown',
            'localUIRight' => true,
            'maxOpenDoorTime' => 0,
            'doorRight' => '1',
            'RightPlan' => [
                [
                    'doorNo' => 1,
                    'planTemplateNo' => '1',
                ],
            ],
            'Valid' => [
                'enable' => true,
                // Far-past start: a device clock behind “this year” otherwise
                // treats the person as not yet valid (authentication failed).
                'beginTime' => '2000-01-01T00:00:00',
                'endTime' => '2037-12-31T23:59:59',
                'timeType' => 'local',
            ],
        ];

        if ($overrides !== []) {
            $valid = $overrides['Valid'] ?? null;
            unset($overrides['Valid']);
            $payload = array_merge($payload, $overrides);
            if (is_array($valid)) {
                $payload['Valid'] = array_merge($payload['Valid'], $valid);
            }
        }

        return $payload;
    }

    /**
     * Hikvision person ID: numeric code like 0003 (not EMP#0003).
     */
    public static function terminalEmployeeNo(Employee|string $employee): string
    {
        $code = $employee instanceof Employee
            ? trim((string) $employee->employee_code)
            : trim((string) $employee);
        if ($code === '') {
            return '';
        }
        $stripped = preg_replace('/^emp#?/i', '', $code) ?? $code;
        if (preg_match('/^\d+$/', $stripped) === 1) {
            $trimmed = ltrim($stripped, '0');
            $digits = $trimmed === '' ? '0' : $trimmed;

            return str_pad($digits, 4, '0', STR_PAD_LEFT);
        }

        return $stripped !== '' ? $stripped : $code;
    }

    /**
     * @return list<string>
     */
    public static function employeeNoLookupVariants(string $terminalNo): array
    {
        $raw = trim($terminalNo);
        $variants = [$raw];
        $normalized = self::terminalEmployeeNo($raw);
        if ($normalized !== '' && $normalized !== $raw) {
            $variants[] = $normalized;
        }
        $digits = ltrim($normalized !== '' ? $normalized : $raw, '0');
        if ($digits === '') {
            $digits = '0';
        }
        if (preg_match('/^\d+$/', $digits) === 1) {
            $variants[] = $digits;
            $variants[] = 'EMP#'.$digits;
            $variants[] = 'EMP#'.str_pad($digits, 4, '0', STR_PAD_LEFT);
            $variants[] = str_pad($digits, 4, '0', STR_PAD_LEFT);
        }

        return array_values(array_unique(array_filter($variants, static fn ($v) => $v !== '')));
    }

    public static function normalizePersonName(string $name): string
    {
        $text = mb_strtolower(trim($name));
        $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function findUniqueEmployeeForTerminalNo(int $orgId, string $terminalNo): ?Employee
    {
        $variants = self::employeeNoLookupVariants($terminalNo);
        if ($variants === []) {
            return null;
        }

        $matches = Employee::with('shift')
            ->where('organization_id', $orgId)
            ->where(function ($q) use ($variants) {
                $q->whereIn('employee_code', $variants)
                    ->orWhereIn('payroll_number', $variants);
            })
            ->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            return null;
        }

        $want = self::terminalEmployeeNo($terminalNo);
        if ($want === '') {
            return null;
        }

        $normalized = Employee::with('shift')
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereNotNull('employee_code')
            ->get()
            ->filter(fn (Employee $row) => self::terminalEmployeeNo($row) === $want);

        return $normalized->count() === 1 ? $normalized->first() : null;
    }

    public static function findUniqueEmployeeByName(int $orgId, string $name): ?Employee
    {
        $want = self::normalizePersonName($name);
        if ($want === '' || strlen($want) < 4) {
            return null;
        }

        $hits = Employee::with('shift')
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->get()
            ->filter(function (Employee $row) use ($want) {
                $full = self::normalizePersonName((string) ($row->full_name ?: trim($row->first_name.' '.$row->last_name)));

                return $full !== '' && $full === $want;
            });

        return $hits->count() === 1 ? $hits->first() : null;
    }

    /**
     * @return array{online: bool, last_seen_at: string|null, version: string|null}
     */
    public function agentStatus(AttendanceClockDevice $device): array
    {
        return $this->agentBridge->agentStatus($device->fresh() ?? $device);
    }

    /**
     * @return array{online: bool, agent: array, error?: string, message?: string}
     */
    public function testAgentConnection(AttendanceClockDevice $device): array
    {
        return $this->agentBridge->pingAgent($device);
    }

    /**
     * Poll the terminal for a punch since the given time (live fingerprint test).
     *
     * @return array{
     *   since: string,
     *   events: list<array>,
     *   latest: array|null,
     *   fingerprint_detected: bool,
     *   applied?: array<string, mixed>
     * }
     */
    public function pollLivePunch(AttendanceClockDevice $device, Carbon $since, bool $apply = false): array
    {
        // Do not require cached features.events — capabilities may be empty until the agent
        // has run a full discovery. DS-K1T terminals expose AcsEvent for attendance.
        $to = AppTimezone::now();
        $client = $this->client($device);
        $caps = $device->capabilities_json ?? null;
        $events = $client->fetchAccessEvents($since, $to, 50, is_array($caps) ? $caps : null);

        $fingerprintEvents = array_values(array_filter(
            $events,
            static fn ($event) => str_contains(
                strtolower((string) ($event['verification_method'] ?? '')),
                'finger',
            ),
        ));

        $latestFp = $fingerprintEvents !== [] ? $fingerprintEvents[count($fingerprintEvents) - 1] : null;
        $latest = $latestFp ?? ($events !== [] ? $events[count($events) - 1] : null);

        $result = [
            'since' => $since->toIso8601String(),
            'events' => $events,
            'latest' => $latest,
            'fingerprint_detected' => $latestFp !== null,
            'via_agent' => $client->lastRequestViaAgent(),
            'agent' => $this->agentBridge->agentStatus($device->fresh() ?? $device),
        ];

        if ($apply && $latest !== null) {
            $result['applied'] = $this->ingestAgentEvents($device, [$latest]);
        }

        return $result;
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

            $normalizedPort = HikvisionIsapiClient::normalizeStoredPort(
                $device->port !== null ? (int) $device->port : null,
                (bool) $device->use_https,
            );
            if ($normalizedPort !== null && $normalizedPort !== (int) $device->port) {
                $device->port = $normalizedPort;
            }

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
                'resolved_port' => HikvisionIsapiClient::resolvePort($device),
                'via_agent' => $client->lastRequestViaAgent(),
                'agent' => $this->agentBridge->agentStatus($device->fresh() ?? $device),
            ];
        } catch (\Throwable $e) {
            $message = self::formatConnectionError($device, $e);
            $device->last_sync_error = mb_substr($message, 0, 500);
            $device->save();

            return [
                'online' => false,
                'device_info' => $device->device_info_json ?? [],
                'capabilities' => $device->capabilities_json ?? [],
                'error' => $message,
                'resolved_port' => HikvisionIsapiClient::resolvePort($device),
                'via_agent' => false,
                'agent' => $this->agentBridge->agentStatus($device->fresh() ?? $device),
            ];
        }
    }

    protected static function formatConnectionError(AttendanceClockDevice $device, \Throwable $e): string
    {
        $raw = $e->getMessage();
        $port = HikvisionIsapiClient::resolvePort($device);
        $storedPort = $device->port;
        $hints = [];

        if ($storedPort === 8000 && ! $device->use_https) {
            $hints[] = 'Port 8000 is usually wrong for Hikvision — ISAPI HTTP uses port 80 (saved value will be corrected automatically on success).';
        }
        if (str_contains($raw, 'Failed to connect') || str_contains($raw, 'cURL error 28')) {
            $hints[] = "Could not reach {$device->host}:{$port} directly from this server.";
            if (! app(HikvisionAgentBridge::class)->isAgentOnline($device)) {
                $hints[] = 'Install and run the Attendance Agent on a PC on the same LAN as the terminal — it proxies all Hikvision management to Centrix cloud.';
            }
            if ($port !== 80 && ! $device->use_https) {
                $hints[] = 'Verify the device HTTP port is 80 (not the Centrix API port 8000).';
            }
        }
        if (str_contains($raw, 'Attendance agent is offline') || str_contains($raw, 'did not respond in time')) {
            $hints[] = 'Download the agent zip from Administration → Attendance clock-in, install on a Windows PC on the office LAN, and ensure it stays running.';
        }
        if (str_contains($raw, '401') || str_contains($raw, 'Unauthorized')) {
            $hints[] = 'Check the device username and password.';
        }

        if ($hints === []) {
            return $raw;
        }

        return $raw.' '.implode(' ', $hints);
    }

    /**
     * Fast page payload: DB + agent heartbeat only.
     * Pass refreshCounts=true to pull live user/card/event counts via the agent (slow).
     *
     * @return array<string, mixed>
     */
    public function overview(AttendanceClockDevice $device, bool $refreshCounts = false): array
    {
        $caps = $device->capabilities_json ?? [];
        $counts = [
            'users' => null,
            'cards' => null,
            'events_today' => null,
        ];

        if ($refreshCounts) {
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
        }

        $orgId = (int) $device->organization_id;
        $mapped = HikvisionEmployeeMapping::query()
            ->where('attendance_clock_device_id', $device->id)
            ->count();
        $centrixEmployees = Employee::query()->where('organization_id', $orgId)->where('is_active', true)->count();
        $agent = $this->agentBridge->agentStatus($device);

        return [
            'device' => $device,
            'online' => $agent['online']
                || (
                    filled($device->last_communication_at)
                    && $device->last_communication_at->gt(AppTimezone::now()->subMinutes(30))
                ),
            'counts' => $counts,
            'agent' => $agent,
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
     * @param  array<string, mixed>  $userInfo
     */
    public function updateUser(AttendanceClockDevice $device, array $userInfo): array
    {
        $this->assertFeature($device, 'users');

        return $this->client($device)->setupUser($userInfo);
    }

    /**
     * @param  array<string, mixed>  $cond
     */
    public function searchCards(AttendanceClockDevice $device, array $cond = []): array
    {
        $this->assertFeature($device, 'cards');

        return $this->client($device)->searchCards($cond);
    }

    /**
     * @param  array<string, mixed>  $cardInfo
     */
    public function createCard(AttendanceClockDevice $device, array $cardInfo): array
    {
        $this->assertFeature($device, 'cards');

        return $this->client($device)->createCard($cardInfo);
    }

    /**
     * @param  array<string, mixed>  $cardInfo
     */
    public function updateCard(AttendanceClockDevice $device, array $cardInfo): array
    {
        $this->assertFeature($device, 'cards');

        return $this->client($device)->setupCard($cardInfo);
    }

    /**
     * @param  array<string, mixed>  $cardInfo
     */
    public function deleteCard(AttendanceClockDevice $device, array $cardInfo): array
    {
        $this->assertFeature($device, 'cards');

        return $this->client($device)->deleteCard($cardInfo);
    }

    /**
     * @param  array<string, mixed>  $cond
     */
    public function searchFingerprints(AttendanceClockDevice $device, array $cond = []): array
    {
        // Cached capabilities often mark fingerprints false because FingerPrint/capabilities
        // 404s on DS-K1T terminals that still support FingerPrintInfo Search.
        return $this->client($device)->searchFingerprints($cond);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deleteFingerprint(AttendanceClockDevice $device, array $payload): array
    {
        return $this->client($device)->deleteFingerprint($payload);
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
            $employeeNo = self::terminalEmployeeNo($employee);
            if ($employeeNo === '') {
                $result['skipped']++;

                continue;
            }

            $name = trim((string) ($employee->full_name ?? $employee->first_name.' '.$employee->last_name));
            $payload = self::terminalUserInfo($employeeNo, $name);

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
            ->flatMap(fn ($v) => self::employeeNoLookupVariants((string) $v))
            ->unique()
            ->all();
        $mappedSet = array_fill_keys($mappedNos, true);

        $unmapped = [];
        foreach ($search['users'] as $row) {
            $no = (string) ($row['employeeNo'] ?? $row['EmployeeNo'] ?? '');
            if ($no === '') {
                continue;
            }
            $alreadyMapped = false;
            foreach (self::employeeNoLookupVariants($no) as $variant) {
                if (isset($mappedSet[$variant])) {
                    $alreadyMapped = true;
                    break;
                }
            }
            if ($alreadyMapped || self::findUniqueEmployeeForTerminalNo($orgId, $no)) {
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
    ): array {
        $employee = Employee::query()->findOrFail($employeeId);
        if ((int) $employee->organization_id !== (int) $device->organization_id) {
            throw ValidationException::withMessages([
                'employee_id' => 'Employee must belong to the same organization as the device.',
            ]);
        }

        // One Centrix employee ↔ one device person per terminal.
        HikvisionEmployeeMapping::query()
            ->where('attendance_clock_device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->where('hikvision_employee_no', '!=', $hikvisionEmployeeNo)
            ->delete();

        $mapping = $this->persistMapping($device, $hikvisionEmployeeNo, $employee);

        // Apply any stored punches that failed while this terminal ID was unmapped.
        $reprocessed = $this->attendanceSync->reprocessPendingEvents(
            $device,
            300,
            $hikvisionEmployeeNo,
        );

        return [
            'mapping' => $mapping,
            'reprocessed' => $reprocessed,
        ];
    }

    /**
     * Map device persons whose employee number or unique name matches Centrix.
     *
     * @return array{mapped: int, skipped: int, retried: int, applied: int, errors: list<string>}
     */
    public function autoMapDeviceUsers(AttendanceClockDevice $device): array
    {
        $this->assertFeature($device, 'users');
        $search = $this->client($device)->searchUsers(['maxResults' => 200]);
        $orgId = (int) $device->organization_id;
        $result = ['mapped' => 0, 'skipped' => 0, 'retried' => 0, 'applied' => 0, 'errors' => []];

        foreach ($search['users'] as $row) {
            $no = trim((string) ($row['employeeNo'] ?? $row['EmployeeNo'] ?? ''));
            if ($no === '') {
                $result['skipped']++;

                continue;
            }

            $existing = HikvisionEmployeeMapping::query()
                ->where('attendance_clock_device_id', $device->id)
                ->whereIn('hikvision_employee_no', self::employeeNoLookupVariants($no))
                ->first();
            if ($existing?->employee_id) {
                $result['skipped']++;

                continue;
            }

            $employee = self::findUniqueEmployeeForTerminalNo($orgId, $no);
            if (! $employee) {
                $name = trim((string) ($row['name'] ?? $row['Name'] ?? ''));
                $employee = $name !== '' ? self::findUniqueEmployeeByName($orgId, $name) : null;
            }
            if (! $employee) {
                $result['skipped']++;

                continue;
            }

            try {
                $this->persistMapping($device, $no, $employee);
                $result['mapped']++;
            } catch (\Throwable $e) {
                $result['errors'][] = "{$no}: {$e->getMessage()}";
            }
        }

        $reprocessed = $this->attendanceSync->reprocessPendingEvents($device);
        $result['applied'] = (int) ($reprocessed['applied'] ?? 0);
        $result['retried'] = (int) ($reprocessed['retried'] ?? 0);
        foreach ($reprocessed['errors'] ?? [] as $error) {
            $result['errors'][] = $error;
        }

        return $result;
    }

    protected function persistMapping(
        AttendanceClockDevice $device,
        string $hikvisionEmployeeNo,
        Employee $employee,
    ): HikvisionEmployeeMapping {
        HikvisionEmployeeMapping::query()
            ->where('attendance_clock_device_id', $device->id)
            ->where('employee_id', $employee->id)
            ->where('hikvision_employee_no', '!=', $hikvisionEmployeeNo)
            ->delete();

        return HikvisionEmployeeMapping::query()->updateOrCreate(
            [
                'attendance_clock_device_id' => $device->id,
                'hikvision_employee_no' => $hikvisionEmployeeNo,
            ],
            [
                'organization_id' => $device->organization_id,
                'employee_id' => $employee->id,
                'sync_status' => 'mapped',
                'last_synced_at' => AppTimezone::now(),
            ]
        );
    }

    /**
     * Pull events via the LAN agent, store raw rows (idempotent), then apply HR attendance rules.
     *
     * @return array{pulled: int, stored: int, applied: int, skipped: int, retried: int, errors: list<string>, via_agent?: bool}
     */
    public function syncAttendance(AttendanceClockDevice $device, ?Carbon $from = null, ?Carbon $to = null): array
    {
        return $this->attendanceSync->syncDevice($device, $from, $to);
    }

    /**
     * Retry stored punches that never applied to Centrix attendance.
     *
     * @return array{stored: int, applied: int, skipped: int, retried: int, errors: list<string>}
     */
    public function reprocessPendingAttendance(AttendanceClockDevice $device, ?string $employeeNo = null): array
    {
        return $this->attendanceSync->reprocessPendingEvents($device, 300, $employeeNo);
    }

    /**
     * Ingest events pushed by the LAN attendance agent (cloud cannot reach device ISAPI).
     *
     * @param  list<array<string, mixed>>  $events
     * @return array{pulled: int, stored: int, applied: int, skipped: int, errors: list<string>}
     */
    public function ingestAgentEvents(AttendanceClockDevice $device, array $events, ?string $agentVersion = null): array
    {
        $this->agentBridge->touchAgent($device, $agentVersion);

        return $this->attendanceSync->ingestEvents($device, $events);
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

        $events = $query->paginate($perPage);
        $events->getCollection()->transform(
            static fn (HikvisionAccessEvent $row) => HikvisionEventNormalizer::present($row)
        );

        return ['events' => $events];
    }

    public static function buildEventKey(int $deviceId, array $event): string
    {
        $serial = trim((string) ($event['serial_no'] ?? data_get($event, 'raw.serialNo') ?? ''));
        // Numeric AcsEvent serials are unique per punch. Non-numeric values are often
        // the device SN reused on every scan — include person + time so new punches ingest.
        if ($serial !== '' && preg_match('/^\d+$/', $serial) === 1) {
            return "d{$deviceId}:s{$serial}";
        }

        $fingerprint = implode('|', [
            $deviceId,
            $serial,
            $event['employee_no'] ?? '',
            $event['punched_at'] ?? '',
            (string) ($event['major'] ?? ''),
            (string) ($event['minor'] ?? ''),
            $event['verification_method'] ?? '',
            $event['attendance_status'] ?? '',
        ]);

        return 'd'.$deviceId.':h'.substr(sha1($fingerprint), 0, 40);
    }

    /**
     * @return bool|null  true/false when discovered; null when capabilities were never stored
     */
    protected function cachedFeature(AttendanceClockDevice $device, string $feature): ?bool
    {
        $caps = $device->capabilities_json;
        if (! is_array($caps) || ! isset($caps['features']) || ! is_array($caps['features'])) {
            return null;
        }
        if (! array_key_exists($feature, $caps['features'])) {
            return null;
        }

        return (bool) $caps['features'][$feature];
    }

    protected function assertFeature(AttendanceClockDevice $device, string $feature): void
    {
        if ($this->cachedFeature($device, $feature) !== false) {
            return;
        }
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
