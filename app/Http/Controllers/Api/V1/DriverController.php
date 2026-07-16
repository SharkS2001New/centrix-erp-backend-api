<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Driver;
use App\Models\Employee;
use App\Models\Sale;
use App\Support\TenantRouteRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DriverController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Driver::class;
    }

    protected function baseQuery(Request $request)
    {
        return parent::baseQuery($request)->with(['defaultVehicle', 'defaultRoute', 'branch', 'employee', 'user']);
    }

    public function show(Request $request, string $id)
    {
        return response()->json(
            $this->findScopedModel($request, $id)->load(['defaultVehicle', 'defaultRoute', 'branch', 'employee', 'user']),
        );
    }

    public function store(Request $request)
    {
        $data = $this->validatedDriver($request);
        $driver = Driver::create($data);

        return response()->json($driver->load(['defaultVehicle', 'defaultRoute', 'branch', 'employee', 'user']), 201);
    }

    public function update(Request $request, string $id)
    {
        $driver = $this->findScopedModel($request, $id);
        $driver->update($this->validatedDriver($request, $driver));

        return response()->json($driver->fresh(['defaultVehicle', 'defaultRoute', 'branch', 'employee', 'user']));
    }

    /** GET /drivers/{id}/deliveries — sales linked to this driver */
    public function deliveries(Request $request, int $driver)
    {
        $this->findScopedModel($request, (string) $driver);

        $query = Sale::query()
            ->where('organization_id', $this->access()->organizationId($request->user(), $request))
            ->where('fulfillment_meta->driver_id', $driver)
            ->orderByDesc('id');

        $this->access()->scopeBranchIfLimited($query, $request->user());

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->input('from_date')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('to_date')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    protected function validatedDriver(Request $request, ?Driver $existing = null): array
    {
        $orgId = (int) ($this->access()->organizationId($request->user(), $request) ?? 0);
        $data = $request->validate([
            'branch_id' => $existing ? 'sometimes|integer|exists:branches,id' : 'required|integer|exists:branches,id',
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                Rule::unique('drivers', 'user_id')->ignore($existing?->id),
            ],
            'employee_id' => [
                'nullable',
                'integer',
                'exists:employees,id',
                Rule::unique('drivers', 'employee_id')->ignore($existing?->id),
            ],
            'default_vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'default_route_id' => TenantRouteRules::nullable($orgId ?: null),
            'driver_code' => ($existing ? 'sometimes|' : '').'required|string|max:45',
            'full_name' => ($existing ? 'sometimes|' : '').'required|string|max:200',
            'phone' => 'nullable|string|max:45',
            'is_active' => 'nullable|boolean',
        ]);

        if ($orgId > 0) {
            $data['organization_id'] = $orgId;
        }

        if (! empty($data['branch_id'])) {
            $this->access()->assertBranchInOrganization($request->user(), (int) $data['branch_id'], $request);
        }

        $employeeId = (int) ($data['employee_id'] ?? 0);
        if ($employeeId > 0) {
            $employee = Employee::query()
                ->whereKey($employeeId)
                ->when($orgId > 0, fn ($q) => $q->where('organization_id', $orgId))
                ->firstOrFail();

            if ($employee->user_id && ! empty($data['user_id']) && (int) $data['user_id'] !== (int) $employee->user_id) {
                throw ValidationException::withMessages([
                    'user_id' => ['This employee is already linked to a different user account. Use the employee account instead of creating or selecting another one.'],
                ]);
            }

            $data['branch_id'] = $employee->branch_id ? (int) $employee->branch_id : ($data['branch_id'] ?? null);
            $data['user_id'] = $employee->user_id ? (int) $employee->user_id : ($data['user_id'] ?? null);
            $data['full_name'] = $employee->full_name ?: Employee::composeFullName(
                $employee->first_name,
                $employee->middle_name,
                $employee->last_name,
                $data['full_name'] ?? null,
            );
            $data['phone'] = $employee->phone ?: ($data['phone'] ?? null);
            if ($employee->organization_id) {
                $data['organization_id'] = (int) $employee->organization_id;
            }
        }

        return $data;
    }
}
