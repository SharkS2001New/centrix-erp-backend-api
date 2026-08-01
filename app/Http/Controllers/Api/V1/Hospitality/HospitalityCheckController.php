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
        $paginator = $this->checks->listRecent((int) $org->id, [
            'status' => $request->input('status'),
            'outlet_id' => $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null,
            'q' => $request->input('q'),
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
            'per_page' => (int) $request->input('per_page', 50),
            'page' => (int) $request->input('page', 1),
        ]);

        $checks = collect($paginator->items())
            ->map(fn ($c) => $this->checks->toArray($c))
            ->values()
            ->all();

        return response()->json([
            'checks' => $checks,
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
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
