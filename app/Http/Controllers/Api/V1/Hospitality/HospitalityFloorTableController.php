<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\HospitalityFloorTable;
use App\Models\HospitalityOutlet;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityCheckService;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HospitalityFloorTableController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityCheckService $checks,
    ) {}

    public function index(Request $request)
    {
        $org = $this->requireOrg($request);
        if (! HospitalityServices::enabled($org, 'floor_tables') && ! HospitalityServices::enabled($org, 'table_pos')) {
            return response()->json([
                'data' => [],
                'floor_tables_enabled' => false,
                'table_pos_enabled' => false,
            ]);
        }

        $outletId = $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null;
        if (! $outletId) {
            $outletId = (int) $this->checks->ensureDefaultOutlet($org, $request->user()?->branch_id ? (int) $request->user()->branch_id : null)->id;
        }

        $tables = HospitalityFloorTable::query()
            ->where('organization_id', $org->id)
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->orderBy('zone')
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $tables,
            'outlet_id' => $outletId,
            'floor_tables_enabled' => HospitalityServices::enabled($org, 'floor_tables'),
            'table_pos_enabled' => HospitalityServices::enabled($org, 'table_pos'),
        ]);
    }

    public function store(Request $request)
    {
        $org = $this->requireOrg($request);
        if (! HospitalityServices::enabled($org, 'floor_tables')) {
            throw ValidationException::withMessages([
                'service' => ['Floor tables are not enabled for this organization.'],
            ]);
        }

        $data = $request->validate([
            'outlet_id' => ['nullable', 'integer'],
            'code' => ['required', 'string', 'max:40'],
            'label' => ['required', 'string', 'max:80'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:100'],
            'zone' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $outlet = isset($data['outlet_id'])
            ? HospitalityOutlet::query()->where('organization_id', $org->id)->where('id', $data['outlet_id'])->firstOrFail()
            : $this->checks->ensureDefaultOutlet($org, $request->user()?->branch_id ? (int) $request->user()->branch_id : null);

        $table = HospitalityFloorTable::create([
            'organization_id' => $org->id,
            'outlet_id' => $outlet->id,
            'code' => strtoupper(trim($data['code'])),
            'label' => $data['label'],
            'seats' => $data['seats'] ?? 4,
            'zone' => $data['zone'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($table, 201);
    }

    public function update(Request $request, int $id)
    {
        $org = $this->requireOrg($request);
        if (! HospitalityServices::enabled($org, 'floor_tables')) {
            throw ValidationException::withMessages([
                'service' => ['Floor tables are not enabled for this organization.'],
            ]);
        }

        $table = HospitalityFloorTable::query()
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:40'],
            'label' => ['sometimes', 'string', 'max:80'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:100'],
            'zone' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }
        $table->update($data);

        return response()->json($table->fresh());
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
