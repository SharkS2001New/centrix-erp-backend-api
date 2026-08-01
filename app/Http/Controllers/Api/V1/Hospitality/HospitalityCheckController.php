<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityCheckService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Hotel Backoffice F&B checks / orders list. */
class HospitalityCheckController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityCheckService $checks,
    ) {}

    public function index(Request $request)
    {
        $org = $this->org($request);
        $outletId = $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null;
        $limit = (int) $request->input('per_page', 100);
        $rows = $this->checks->listRecent(
            (int) $org->id,
            $request->input('status'),
            $outletId,
            $limit,
        );

        return response()->json([
            'checks' => array_map(fn ($c) => $this->checks->toArray($c), $rows),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $org = $this->org($request);
        $check = $this->checks->findOwnedCheck($id, (int) $org->id);

        return response()->json(['check' => $this->checks->toArray($check)]);
    }

    protected function org(Request $request): Organization
    {
        $org = $this->erp->organizationForUser($request->user());
        if (! $org) {
            throw ValidationException::withMessages(['organization' => ['No organization context.']]);
        }

        return $org;
    }
}
