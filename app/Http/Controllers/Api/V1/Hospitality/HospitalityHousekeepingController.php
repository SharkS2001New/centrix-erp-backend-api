<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityHousekeepingService;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HospitalityHousekeepingController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityHousekeepingService $housekeeping,
    ) {}

    public function board(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);

        return response()->json($this->housekeeping->board($org));
    }

    public function setStatus(Request $request, int $roomId)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'status' => ['required', 'in:vacant,occupied,dirty,clean,ooo'],
            'housekeeping_assigned_to' => ['nullable', 'integer'],
            'housekeeping_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $extra = [];
        if ($request->exists('housekeeping_assigned_to')) {
            $extra['housekeeping_assigned_to'] = $data['housekeeping_assigned_to'] ?? null;
        }
        if ($request->exists('housekeeping_notes')) {
            $extra['housekeeping_notes'] = $data['housekeeping_notes'] ?? null;
        }

        return response()->json([
            'room' => $this->housekeeping->setStatus($org, $roomId, $data['status'], $extra),
        ]);
    }

    protected function assertEnabled(Organization $org): void
    {
        if (! HospitalityServices::enabled($org, 'housekeeping')) {
            throw ValidationException::withMessages([
                'service' => ['Housekeeping is not enabled for this organization.'],
            ]);
        }
    }

    protected function org(Request $request): Organization
    {
        return $this->erp->resolveOrganization($request);
    }
}
