<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AttendanceClockDevice;
use App\Services\Attendance\Hikvision\HikvisionService;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

class HikvisionDeviceController extends HrOrgResourceController
{
    public function __construct(
        protected HikvisionService $hikvision,
        protected AuditLogger $audit,
    ) {
    }

    protected function modelClass(): string
    {
        return AttendanceClockDevice::class;
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        return [];
    }

    public function testConnection(string $id)
    {
        $device = $this->findHikvisionDevice($id);

        return response()->json($this->hikvision->testAgentConnection($device));
    }

    /**
     * Poll recent device events for a live fingerprint / attendance test.
     */
    public function pollLivePunch(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'since' => 'nullable|date',
            'apply' => 'nullable|boolean',
        ]);

        $since = isset($data['since'])
            ? \Carbon\Carbon::parse($data['since'])
            : \App\Support\AppTimezone::now()->subSeconds(20);

        return response()->json($this->hikvision->pollLivePunch(
            $device,
            $since,
            (bool) ($data['apply'] ?? false),
        ));
    }

    public function overview(string $id)
    {
        $device = $this->findHikvisionDevice($id);

        $overview = $this->hikvision->overview($device);
        $overview['agent'] = $this->hikvision->agentStatus($device);

        return response()->json($overview);
    }

    public function agentStatus(string $id)
    {
        $device = $this->findHikvisionDevice($id);

        return response()->json($this->hikvision->agentStatus($device));
    }

    /**
     * LAN agent polls for pending ISAPI proxy commands.
     */
    public function pullAgentCommands(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:10',
            'agent_version' => 'nullable|string|max:40',
        ]);

        $commands = app(\App\Services\Attendance\Hikvision\HikvisionAgentBridge::class)->pullPendingCommands(
            $device,
            (int) ($data['limit'] ?? 5),
            $data['agent_version'] ?? null,
        );

        return response()->json(['commands' => $commands]);
    }

    /**
     * LAN agent submits ISAPI proxy command results.
     */
    public function submitAgentCommandResult(Request $request, string $id, string $commandId)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'success' => 'required|boolean',
            'status' => 'nullable|integer|min:100|max:599',
            'headers' => 'nullable|array',
            'body' => 'nullable|string|max:500000',
            'error' => 'nullable|string|max:2000',
            'agent_version' => 'nullable|string|max:40',
        ]);

        app(\App\Services\Attendance\Hikvision\HikvisionAgentBridge::class)->submitCommandResult(
            $device,
            $commandId,
            $data,
        );

        return response()->json(['ok' => true]);
    }

    public function capabilities(string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $result = $this->hikvision->connect($device, refreshCapabilities: true);

        return response()->json([
            'capabilities' => $result['capabilities'] ?? [],
            'online' => $result['online'] ?? false,
            'error' => $result['error'] ?? null,
            'fetched_at' => optional($device->fresh()->capabilities_fetched_at)?->toIso8601String(),
        ]);
    }

    public function searchUsers(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'searchResultPosition' => 'nullable|integer|min:0',
            'maxResults' => 'nullable|integer|min:1|max:100',
            'fuzzySearch' => 'nullable|string|max:100',
            'employee_no' => 'nullable|string|max:64',
        ]);

        $cond = [
            'searchResultPosition' => $data['searchResultPosition'] ?? 0,
            'maxResults' => $data['maxResults'] ?? 30,
            'fuzzySearch' => $data['fuzzySearch'] ?? null,
        ];
        if (! empty($data['employee_no'])) {
            $cond['EmployeeNoList'] = [['employeeNo' => $data['employee_no']]];
        }

        return response()->json($this->hikvision->searchUsers($device, $cond));
    }

    public function createUser(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'employeeNo' => 'required|string|max:64',
            'name' => 'required|string|max:200',
            'userType' => 'nullable|string|max:40',
            'Valid' => 'nullable|array',
        ]);

        $payload = array_merge([
            'userType' => 'normal',
            'Valid' => [
                'enable' => true,
                'beginTime' => now()->startOfYear()->format('Y-m-d\TH:i:s'),
                'endTime' => '2037-12-31T23:59:59',
            ],
        ], $data);

        return response()->json($this->hikvision->createUser($device, $payload));
    }

    public function updateUser(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'employeeNo' => 'required|string|max:64',
            'name' => 'required|string|max:200',
            'userType' => 'nullable|string|max:40',
            'Valid' => 'nullable|array',
        ]);

        return response()->json($this->hikvision->updateUser($device, $data));
    }

    public function deleteUsers(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'employee_nos' => 'required|array|min:1|max:50',
            'employee_nos.*' => 'string|max:64',
            'delete_all' => 'nullable|boolean',
        ]);

        if ($data['delete_all'] ?? false) {
            abort_unless(
                $request->boolean('confirmed'),
                422,
                'Deleting all device users requires confirmed=true.'
            );
            $search = $this->hikvision->searchUsers($device, ['maxResults' => 500]);
            $data['employee_nos'] = array_values(array_filter(array_map(
                static fn ($row) => (string) ($row['employeeNo'] ?? $row['EmployeeNo'] ?? ''),
                $search['users'] ?? []
            )));
        }

        $result = $this->hikvision->deleteUsers($device, $data['employee_nos']);
        $this->auditHikvision($request, $device, 'hikvision.delete_users', [
            'employee_nos' => $data['employee_nos'],
            'delete_all' => (bool) ($data['delete_all'] ?? false),
        ]);

        return response()->json($result);
    }

    public function searchCards(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'searchResultPosition' => 'nullable|integer|min:0',
            'maxResults' => 'nullable|integer|min:1|max:100',
            'employee_no' => 'nullable|string|max:64',
        ]);
        $cond = [
            'searchResultPosition' => $data['searchResultPosition'] ?? 0,
            'maxResults' => $data['maxResults'] ?? 30,
        ];
        if (! empty($data['employee_no'])) {
            $cond['EmployeeNoList'] = [['employeeNo' => $data['employee_no']]];
        }

        return response()->json($this->hikvision->searchCards($device, $cond));
    }

    public function createCard(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'employeeNo' => 'required|string|max:64',
            'cardNo' => 'required|string|max:64',
            'cardType' => 'nullable|string|max:40',
        ]);

        $payload = array_merge(['cardType' => 'normalCard'], $data);

        return response()->json($this->hikvision->createCard($device, $payload));
    }

    public function updateCard(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'employeeNo' => 'required|string|max:64',
            'cardNo' => 'required|string|max:64',
            'cardType' => 'nullable|string|max:40',
        ]);

        return response()->json($this->hikvision->updateCard($device, $data));
    }

    public function deleteCard(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'employeeNo' => 'required|string|max:64',
            'cardNo' => 'required|string|max:64',
        ]);

        $result = $this->hikvision->deleteCard($device, $data);
        $this->auditHikvision($request, $device, 'hikvision.delete_card', $data);

        return response()->json($result);
    }

    public function fingerprintCapabilities(string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $caps = $device->capabilities_json['fingerprints'] ?? null;
        if ($caps === null) {
            $this->hikvision->connect($device, refreshCapabilities: true);
            $device->refresh();
            $caps = $device->capabilities_json['fingerprints'] ?? null;
        }

        $remoteEnroll = $device->capabilities_json['features']['remote_fingerprint_enrollment'] ?? false;

        return response()->json([
            'capabilities' => $caps,
            'remote_enrollment_supported' => $remoteEnroll,
            'enrollment_message' => $remoteEnroll
                ? null
                : 'Fingerprint enrollment must be completed on the terminal.',
        ]);
    }

    public function searchFingerprints(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'searchResultPosition' => 'nullable|integer|min:0',
            'maxResults' => 'nullable|integer|min:1|max:100',
            'employee_no' => 'nullable|string|max:64',
        ]);
        $cond = [
            'searchResultPosition' => $data['searchResultPosition'] ?? 0,
            'maxResults' => $data['maxResults'] ?? 30,
        ];
        if (! empty($data['employee_no'])) {
            $cond['EmployeeNoList'] = [['employeeNo' => $data['employee_no']]];
        }

        return response()->json($this->hikvision->searchFingerprints($device, $cond));
    }

    public function deleteFingerprint(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'employeeNo' => 'required|string|max:64',
            'fingerPrintID' => 'required|integer|min:1|max:10',
        ]);

        $result = $this->hikvision->deleteFingerprint($device, $data);
        $this->auditHikvision($request, $device, 'hikvision.delete_fingerprint', $data);

        return response()->json($result);
    }

    public function searchEvents(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'startTime' => 'nullable|date',
            'endTime' => 'nullable|date',
            'maxResults' => 'nullable|integer|min:1|max:1000',
            'eventAttribute' => 'nullable|string|max:40',
        ]);

        return response()->json($this->hikvision->searchEvents($device, $data));
    }

    public function storedEvents(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $filters = $request->validate([
            'employee_no' => 'nullable|string|max:64',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        return response()->json($this->hikvision->listStoredEvents($device, $filters));
    }

    public function syncEmployeesToDevice(string $id)
    {
        $device = $this->findHikvisionDevice($id);

        return response()->json($this->hikvision->syncEmployeesToDevice($device));
    }

    public function syncEmployeesFromDevice(string $id)
    {
        $device = $this->findHikvisionDevice($id);

        return response()->json($this->hikvision->syncEmployeesFromDevice($device));
    }

    public function mapEmployee(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'hikvision_employee_no' => 'required|string|max:64',
        ]);

        $mapping = $this->hikvision->mapEmployee(
            $device,
            $data['hikvision_employee_no'],
            (int) $data['employee_id'],
        );

        return response()->json($mapping, 201);
    }

    public function syncAttendance(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = isset($data['from']) ? \Carbon\Carbon::parse($data['from']) : null;
        $to = isset($data['to']) ? \Carbon\Carbon::parse($data['to']) : null;

        return response()->json($this->hikvision->syncAttendance($device, $from, $to));
    }

    /**
     * LAN attendance agent pushes events here when cloud API cannot reach the device.
     */
    public function ingestAgentEvents(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $data = $request->validate([
            'events' => 'required|array|min:1|max:500',
            'events.*.employee_no' => 'required|string|max:64',
            'events.*.punched_at' => 'required|date',
            'events.*.employee_name' => 'nullable|string|max:200',
            'events.*.attendance_status' => 'nullable|string|max:40',
            'events.*.verification_method' => 'nullable|string|max:80',
            'events.*.card_no' => 'nullable|string|max:64',
            'events.*.serial_no' => 'nullable|string|max:64',
            'events.*.major' => 'nullable|integer',
            'events.*.minor' => 'nullable|integer',
            'events.*.raw' => 'nullable|array',
            'agent_version' => 'nullable|string|max:40',
        ]);

        $result = $this->hikvision->ingestAgentEvents(
            $device,
            $data['events'],
            $data['agent_version'] ?? null,
        );
        $result['pulled'] = count($data['events']);

        return response()->json($result);
    }

    protected function findHikvisionDevice(string $id): AttendanceClockDevice
    {
        /** @var AttendanceClockDevice $device */
        $device = $this->findScoped($id);
        abort_unless($device->provider === 'hikvision' && filled($device->host), 422, 'Device is not configured for Hikvision ISAPI.');

        return $device;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function auditHikvision(Request $request, AttendanceClockDevice $device, string $action, array $context): void
    {
        $user = $request->user();
        if (! $user) {
            return;
        }

        $this->audit->log(
            $user,
            $action,
            'attendance_clock_devices',
            $device->id,
            null,
            array_merge(['device_no' => $device->device_no], $context),
            $request,
        );
    }
}
