<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Api\V1\BaseResourceController;
use App\Models\HospitalityRoomType;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
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

    public function store(Request $request)
    {
        $this->assertRoomsEnabled($request);

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
