<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityNightAuditService;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HospitalityNightAuditController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityNightAuditService $nightAudit,
    ) {}

    public function preview(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);

        return response()->json(
            $this->nightAudit->preview($org, $request->input('business_date'))
        );
    }

    public function run(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'business_date' => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->nightAudit->run($org, $request->user(), $data['business_date'] ?? null)
        );
    }

    public function history(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);

        return response()->json([
            'data' => $this->nightAudit->history($org),
        ]);
    }

    protected function assertEnabled(Organization $org): void
    {
        if (! HospitalityServices::enabled($org, 'night_audit')) {
            throw ValidationException::withMessages([
                'service' => ['Night audit is not enabled for this organization.'],
            ]);
        }
    }

    protected function org(Request $request): Organization
    {
        return $this->erp->resolveOrganization($request);
    }
}
