<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sale;
use App\Models\Vehicle;
use App\Support\OrganizationIdResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Vehicle::class;
    }

    protected function baseQuery(Request $request)
    {
        return parent::baseQuery($request)->with('branch');
    }

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return ['vehicle_name', 'plate_number', 'vehicle_code'];
    }

    public function store(Request $request)
    {
        $data = $this->validatedVehicle($request);
        if ($request->user()) {
            $this->applyBranchScopeToWriteData($request->user(), $data, $request);
        }
        $vehicle = Vehicle::create($data);

        return response()->json($vehicle->load('branch'), 201);
    }

    public function update(Request $request, string $id)
    {
        $vehicle = $this->findScopedModel($request, $id);
        $vehicle->update($this->validatedVehicle($request, $vehicle));

        return response()->json($vehicle->fresh('branch'));
    }

    public function show(Request $request, string $id)
    {
        return response()->json($this->findScopedModel($request, $id)->load('branch'));
    }

    /** GET /vehicles/{id}/deliveries — completed sales linked via fulfillment metadata */
    public function deliveries(Request $request, int $vehicle)
    {
        $this->findScopedModel($request, (string) $vehicle);

        $query = Sale::query()
            ->where('organization_id', $this->access()->organizationId($request->user(), $request))
            ->where('fulfillment_meta->vehicle_id', $vehicle)
            ->orderByDesc('id');

        $this->access()->scopeBranchIfLimited($query, $request->user());

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->where('status', 'completed');
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    protected function validatedVehicle(Request $request, ?Vehicle $existing = null): array
    {
        $orgId = (int) ($this->access()->organizationId($request->user(), $request) ?? 0);
        $branchId = (int) ($request->input('branch_id') ?? $existing?->branch_id ?? 0);

        $data = $request->validate([
            'branch_id' => $existing ? 'sometimes|integer|exists:branches,id' : 'required|integer|exists:branches,id',
            'vehicle_code' => [
                ($existing ? 'sometimes|' : '').'required',
                'string',
                'max:45',
                Rule::unique('vehicles', 'vehicle_code')
                    ->where(fn ($query) => $query->where('branch_id', $branchId ?: $request->input('branch_id')))
                    ->ignore($existing?->id),
            ],
            'vehicle_name' => ($existing ? 'sometimes|' : '').'required|string|max:200',
            'plate_number' => 'nullable|string|max:45',
            'max_weight_kg' => 'nullable|numeric|min:0',
            'max_volume_m3' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if (! empty($data['branch_id'])) {
            $this->access()->assertBranchInOrganization($request->user(), (int) $data['branch_id'], $request);
        }

        if ($orgId > 0) {
            $data['organization_id'] = $orgId;
        } elseif (! empty($data['branch_id'])) {
            $data['organization_id'] = OrganizationIdResolver::requireForBranch((int) $data['branch_id']);
        }

        return $data;
    }
}
