<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Api\V1\BaseResourceController;
use App\Models\HospitalityRoom;
use App\Models\HospitalityRoomType;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityRoomInventoryService;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HospitalityRoomController extends BaseResourceController
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityRoomInventoryService $inventory,
    ) {}

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
        return ['room_number', 'floor', 'status', 'guest_name'];
    }

    public function index(Request $request)
    {
        $this->assertRoomsEnabled($request);
        $org = $this->org($request);
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
                    ->orWhere('status', 'like', "%{$q}%")
                    ->orWhere('guest_name', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $this->applyListOrdering(
            $request,
            $query,
            $this->defaultListOrderColumn(),
            $this->defaultListOrderDirection(),
        );

        $page = $query->paginate($perPage);
        $presented = $this->inventory->presentMany($org, $page->getCollection());
        $page->setCollection(collect($presented));

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $this->assertRoomsEnabled($request);
        $org = $this->org($request);
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'],
            'room_number' => [
                'required',
                'string',
                'max:40',
                Rule::unique('hospitality_rooms', 'room_number')->where(
                    fn ($q) => $q->where('organization_id', $org->id)
                ),
            ],
            'floor' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $this->assertRoomType($org, (int) $data['room_type_id']);
        $status = $data['status'] ?? 'vacant';
        $this->inventory->assertInventoryStatus($status, true);

        $user = $request->user();
        $payload = [
            'organization_id' => $org->id,
            'room_type_id' => (int) $data['room_type_id'],
            'room_number' => trim((string) $data['room_number']),
            'floor' => isset($data['floor']) && trim((string) $data['floor']) !== ''
                ? trim((string) $data['floor'])
                : null,
            'status' => $status ?: 'vacant',
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
        if (! empty($data['branch_id'])) {
            $payload['branch_id'] = (int) $data['branch_id'];
        } elseif ($user?->branch_id) {
            $payload['branch_id'] = (int) $user->branch_id;
        }

        $model = HospitalityRoom::create($payload);
        $model->load(['roomType:id,code,name,base_rate,max_occupancy']);

        return response()->json($this->inventory->presentRoom($org, $model), 201);
    }

    public function bulkStore(Request $request)
    {
        $this->assertRoomsEnabled($request);
        $org = $this->org($request);
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'],
            'start_number' => ['required', 'string', 'max:40'],
            'count' => ['required', 'integer', 'min:1', 'max:50'],
            'floor' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        if ($request->user()?->branch_id && empty($data['branch_id'])) {
            $data['branch_id'] = (int) $request->user()->branch_id;
        }

        $rooms = $this->inventory->createRange($org, $data);

        return response()->json(['data' => $rooms, 'count' => count($rooms)], 201);
    }

    public function show(Request $request, string $id)
    {
        $this->assertRoomsEnabled($request);
        $org = $this->org($request);
        $model = $this->findScopedModel($request, $id);
        $model->load(['roomType:id,code,name,base_rate,max_occupancy']);
        $folio = $this->inventory->openFolioForRoom($org, (int) $model->id);

        return response()->json($this->inventory->presentRoom($org, $model, $folio));
    }

    public function update(Request $request, string $id)
    {
        $this->assertRoomsEnabled($request);
        $org = $this->org($request);
        $model = $this->findScopedModel($request, $id);
        $data = $request->validate([
            'room_type_id' => ['sometimes', 'integer'],
            'room_number' => [
                'sometimes',
                'string',
                'max:40',
                Rule::unique('hospitality_rooms', 'room_number')
                    ->where(fn ($q) => $q->where('organization_id', $org->id))
                    ->ignore($model->id),
            ],
            'floor' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        if (isset($data['room_type_id'])) {
            $this->assertRoomType($org, (int) $data['room_type_id']);
        }
        if (array_key_exists('status', $data)) {
            $this->inventory->assertCanChangeStatus($org, $model, $data['status']);
        }

        $payload = [];
        foreach (['room_type_id', 'room_number', 'floor', 'status', 'is_active', 'branch_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $data[$key];
            }
        }
        if (array_key_exists('room_number', $payload)) {
            $payload['room_number'] = trim((string) $payload['room_number']);
        }
        if (array_key_exists('floor', $payload)) {
            $floor = trim((string) ($payload['floor'] ?? ''));
            $payload['floor'] = $floor !== '' ? $floor : null;
        }

        $model->update($payload);
        $model->load(['roomType:id,code,name,base_rate,max_occupancy']);
        $folio = $this->inventory->openFolioForRoom($org, (int) $model->id);

        return response()->json($this->inventory->presentRoom($org, $model->fresh(), $folio));
    }

    public function destroy(Request $request, string $id)
    {
        $this->assertRoomsEnabled($request);
        $org = $this->org($request);
        $model = $this->findScopedModel($request, $id);
        $this->inventory->assertCanDelete($org, $model);

        return parent::destroy($request, $id);
    }

    protected function assertRoomType(Organization $org, int $typeId): void
    {
        $exists = HospitalityRoomType::query()
            ->where('organization_id', $org->id)
            ->where('id', $typeId)
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'room_type_id' => ['Room type was not found.'],
            ]);
        }
    }

    protected function assertRoomsEnabled(Request $request): void
    {
        $org = $this->org($request);
        if (! HospitalityServices::enabled($org, 'rooms')) {
            throw ValidationException::withMessages([
                'service' => ['Rooms are not enabled for this organization. Ask your Centrix administrator.'],
            ]);
        }
    }

    protected function org(Request $request): Organization
    {
        $org = $this->erp->organizationForUser($request->user());
        if (! $org instanceof Organization) {
            throw ValidationException::withMessages(['organization' => ['No organization context.']]);
        }

        return $org;
    }
}
