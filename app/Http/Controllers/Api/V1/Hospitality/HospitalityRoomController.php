<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Api\V1\BaseResourceController;
use App\Models\HospitalityRoom;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HospitalityRoomController extends BaseResourceController
{
    public function __construct(protected ErpContext $erp) {}

    protected function modelClass(): string
    {
        return HospitalityRoom::class;
    }

    protected function defaultListOrderColumn(): ?string
    {
        return 'room_number';
    }

    protected function defaultListOrderDirection(): string
    {
        return 'asc';
    }

    protected function searchColumns(): array
    {
        return ['room_number', 'floor', 'status'];
    }

    public function index(Request $request)
    {
        $this->assertRoomsEnabled($request);
        $query = $this->baseQuery($request)->with(['roomType:id,code,name,base_rate,max_occupancy']);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true) && $val !== null && $val !== '') {
                $query->where($col, $val);
            }
        }
        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('room_number', 'like', "%{$q}%")
                    ->orWhere('floor', 'like', "%{$q}%")
                    ->orWhere('status', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $this->applyListOrdering(
            $request,
            $query,
            $this->defaultListOrderColumn(),
            $this->defaultListOrderDirection(),
        );

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $this->assertRoomsEnabled($request);
        $request->validate([
            'room_type_id' => ['required', 'integer'],
            'room_number' => ['required', 'string', 'max:40'],
            'floor' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'in:vacant,occupied,dirty,clean,ooo'],
            'is_active' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        return parent::store($request);
    }

    public function show(Request $request, string $id)
    {
        $this->assertRoomsEnabled($request);
        $model = $this->findScopedModel($request, $id);
        $model->load(['roomType:id,code,name,base_rate,max_occupancy']);

        return response()->json($model);
    }

    public function update(Request $request, string $id)
    {
        $this->assertRoomsEnabled($request);
        $request->validate([
            'room_type_id' => ['sometimes', 'integer'],
            'room_number' => ['sometimes', 'string', 'max:40'],
            'floor' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'in:vacant,occupied,dirty,clean,ooo'],
            'is_active' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        return parent::update($request, $id);
    }

    public function destroy(Request $request, string $id)
    {
        $this->assertRoomsEnabled($request);

        return parent::destroy($request, $id);
    }

    protected function assertRoomsEnabled(Request $request): void
    {
        $org = $this->erp->organizationForUser($request->user());
        if (! $org instanceof Organization || ! HospitalityServices::enabled($org, 'rooms')) {
            throw ValidationException::withMessages([
                'service' => ['Rooms are not enabled for this organization. Ask your Centrix administrator.'],
            ]);
        }
    }
}
