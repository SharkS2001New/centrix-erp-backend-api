<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AttendanceClockDevice;
use App\Services\Attendance\Hikvision\HikvisionService;
use Illuminate\Http\Request;

class HikvisionDeviceController extends HrOrgResourceController
{
    public function __construct(
        protected HikvisionService $hikvision,
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

        return response()->json($this->hikvision->connect($device, refreshCapabilities: true));
    }

    public function overview(string $id)
    {
        $device = $this->findHikvisionDevice($id);

        return response()->json($this->hikvision->overview($device));
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

        return response()->json($this->hikvision->deleteUsers($device, $data['employee_nos']));
    }

    public function searchCards(Request $request, string $id)
    {
        $device = $this->findHikvisionDevice($id);
        $this->hikvision->client($device);
        $caps = $device->capabilities_json ?? [];
        abort_unless($caps['features']['cards'] ?? false, 422, 'Card management is not supported by this terminal.');

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

        return response()->json($this->hikvision->client($device)->searchCards($cond));
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

    protected function findHikvisionDevice(string $id): AttendanceClockDevice
    {
        /** @var AttendanceClockDevice $device */
        $device = $this->findScoped($id);
        abort_unless($device->provider === 'hikvision' && filled($device->host), 422, 'Device is not configured for Hikvision ISAPI.');

        return $device;
    }
}
