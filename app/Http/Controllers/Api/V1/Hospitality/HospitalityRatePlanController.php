<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityRatePlanService;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HospitalityRatePlanController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityRatePlanService $ratePlans,
    ) {}

    public function index(Request $request)
    {
        $org = $this->org($request);
        $this->assertRooms($org);
        $roomTypeId = $request->filled('room_type_id') ? (int) $request->input('room_type_id') : null;

        return response()->json(['data' => $this->ratePlans->list($org, $roomTypeId)]);
    }

    public function store(Request $request)
    {
        $org = $this->org($request);
        $this->assertRooms($org);
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $plan = $this->ratePlans->upsert($org, $data);

        return response()->json(['rate_plan' => $this->ratePlans->toArray($plan)], 201);
    }

    public function update(Request $request, int $id)
    {
        $org = $this->org($request);
        $this->assertRooms($org);
        $data = $request->validate([
            'room_type_id' => ['sometimes', 'integer'],
            'code' => ['sometimes', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'max:120'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $plan = $this->ratePlans->upsert($org, $data, $id);

        return response()->json(['rate_plan' => $this->ratePlans->toArray($plan)]);
    }

    public function destroy(Request $request, int $id)
    {
        $org = $this->org($request);
        $this->assertRooms($org);
        $this->ratePlans->delete($org, $id);

        return response()->json(['ok' => true]);
    }

    protected function assertRooms(Organization $org): void
    {
        if (! HospitalityServices::enabled($org, 'rooms')) {
            throw ValidationException::withMessages([
                'service' => ['Rooms are not enabled for this organization.'],
            ]);
        }
    }

    protected function org(Request $request): Organization
    {
        return $this->erp->resolveOrganization($request);
    }
}
