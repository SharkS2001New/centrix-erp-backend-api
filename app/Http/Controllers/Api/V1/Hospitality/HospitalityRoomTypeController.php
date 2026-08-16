<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Api\V1\BaseResourceController;
use App\Models\HospitalityRoomType;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HospitalityRoomTypeController extends BaseResourceController
{
    public function __construct(protected ErpContext $erp) {}

    protected function modelClass(): string
    {
        return HospitalityRoomType::class;
    }

    protected function defaultListOrderColumn(): ?string
    {
        return 'name';
    }

    protected function defaultListOrderDirection(): string
    {
        return 'asc';
    }

    public function index(Request $request)
    {
        $this->assertRoomsEnabled($request);

        return parent::index($request);
    }

    protected function searchColumns(): array
    {
        return ['code', 'name'];
    }

    public function store(Request $request)
    {
        $this->assertRoomsEnabled($request);
        $this->validateRoomType($request);

        return parent::store($request);
    }

    public function show(Request $request, string $id)
    {
        $this->assertRoomsEnabled($request);

        return parent::show($request, $id);
    }

    public function update(Request $request, string $id)
    {
        $this->assertRoomsEnabled($request);
        $this->validateRoomType($request, $id);

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

    protected function validateRoomType(Request $request, ?string $ignoreId = null): void
    {
        $org = $this->erp->organizationForUser($request->user());
        $orgId = $org instanceof Organization ? (int) $org->id : 0;
        $unique = Rule::unique('hospitality_room_types', 'code')->where(
            fn ($q) => $q->where('organization_id', $orgId),
        );
        if ($ignoreId) {
            $unique = $unique->ignore($ignoreId);
        }

        $request->validate([
            'code' => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:40', $unique],
            'name' => [$ignoreId ? 'sometimes' : 'required', 'string', 'max:120'],
            'base_rate' => ['nullable', 'numeric', 'min:0'],
            'max_occupancy' => ['nullable', 'integer', 'min:1', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
