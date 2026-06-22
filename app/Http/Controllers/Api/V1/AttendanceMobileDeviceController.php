<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceMobileDevice;
use App\Services\Attendance\AttendanceMobileDeviceService;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;

class AttendanceMobileDeviceController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected AttendanceMobileDeviceService $devices,
    ) {}

    public function index(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);

        $devices = AttendanceMobileDevice::query()
            ->with([
                'registeredByUser:id,full_name,username',
                'branch:id,branch_name,branch_code',
            ])
            ->where('organization_id', $org->id)
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->input('per_page', 50), 200));

        return response()->json($devices);
    }

    public function store(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $user = $request->user();

        $data = $request->validate([
            'device_identifier' => 'required|string|max:120',
            'branch_id' => 'required|integer',
            'device_label' => 'nullable|string|max:120',
            'platform' => 'nullable|string|max:32',
        ]);

        $device = $this->devices->register(
            $org,
            $user,
            $data['device_identifier'],
            (int) $data['branch_id'],
            $data['device_label'] ?? null,
            $data['platform'] ?? null,
        );

        return response()->json($device->load([
            'registeredByUser:id,full_name,username',
            'branch:id,branch_name,branch_code',
        ]), 201);
    }

    public function update(Request $request, string $id)
    {
        $org = $this->erp->resolveOrganization($request);

        $device = AttendanceMobileDevice::query()
            ->where('organization_id', $org->id)
            ->findOrFail((int) $id);

        $data = $request->validate([
            'device_label' => 'nullable|string|max:120',
            'branch_id' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($data['branch_id'])) {
            $this->devices->validateBranch($org, (int) $data['branch_id']);
        }

        $device->fill($data);
        $device->save();

        return response()->json($device->fresh([
            'registeredByUser:id,full_name,username',
            'branch:id,branch_name,branch_code',
        ]));
    }

    public function destroy(Request $request, string $id)
    {
        $org = $this->erp->resolveOrganization($request);

        $device = AttendanceMobileDevice::query()
            ->where('organization_id', $org->id)
            ->findOrFail((int) $id);

        $device->delete();

        return response()->json(['message' => 'Attendance phone removed.']);
    }
}
