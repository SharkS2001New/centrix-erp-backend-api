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
        ]);

        return response()->json([
            'room' => $this->housekeeping->setStatus($org, $roomId, $data['status']),
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
