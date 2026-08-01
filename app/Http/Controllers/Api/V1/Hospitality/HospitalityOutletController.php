<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\HospitalityOutlet;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityCheckService;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HospitalityOutletController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityCheckService $checks,
    ) {}

    public function index(Request $request)
    {
        $org = $this->requireOrg($request);
        $this->checks->ensureDefaultOutlet($org, $request->user()?->branch_id ? (int) $request->user()->branch_id : null);

        $query = HospitalityOutlet::query()
            ->where('organization_id', $org->id)
            ->orderBy('id');

        if (! HospitalityServices::enabled($org, 'extra_outlets')) {
            $query->where('code', 'MAIN');
        }

        $outlets = $query->get();

        return response()->json([
            'data' => $outlets,
            'extra_outlets_enabled' => HospitalityServices::enabled($org, 'extra_outlets'),
            'floor_tables_enabled' => HospitalityServices::enabled($org, 'floor_tables'),
        ]);
    }

    public function store(Request $request)
    {
        $org = $this->requireOrg($request);
        if (! HospitalityServices::enabled($org, 'extra_outlets')) {
            throw ValidationException::withMessages([
                'service' => ['Extra outlets are not enabled for this organization.'],
            ]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'outlet_type' => ['nullable', 'string', 'in:bar,restaurant,other'],
            'branch_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $outlet = HospitalityOutlet::create([
            'organization_id' => $org->id,
            'branch_id' => $data['branch_id'] ?? $request->user()?->branch_id,
            'code' => strtoupper(trim($data['code'])),
            'name' => $data['name'],
            'outlet_type' => $data['outlet_type'] ?? 'bar',
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($outlet, 201);
    }

    public function update(Request $request, int $id)
    {
        $org = $this->requireOrg($request);
        $outlet = HospitalityOutlet::query()
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($outlet->code === 'MAIN') {
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'outlet_type' => ['nullable', 'string', 'in:bar,restaurant,other'],
                'is_active' => ['nullable', 'boolean'],
            ]);
        } else {
            if (! HospitalityServices::enabled($org, 'extra_outlets')) {
                throw ValidationException::withMessages([
                    'service' => ['Extra outlets are not enabled for this organization.'],
                ]);
            }
            $data = $request->validate([
                'code' => ['sometimes', 'string', 'max:40'],
                'name' => ['sometimes', 'string', 'max:120'],
                'outlet_type' => ['nullable', 'string', 'in:bar,restaurant,other'],
                'is_active' => ['nullable', 'boolean'],
                'branch_id' => ['nullable', 'integer'],
            ]);
            if (isset($data['code'])) {
                $data['code'] = strtoupper(trim($data['code']));
            }
        }

        $outlet->update($data);

        return response()->json($outlet->fresh());
    }

    protected function requireOrg(Request $request): Organization
    {
        $org = $this->erp->organizationForUser($request->user());
        if (! $org) {
            throw ValidationException::withMessages(['organization' => ['No organization context.']]);
        }

        return $org;
    }
}
