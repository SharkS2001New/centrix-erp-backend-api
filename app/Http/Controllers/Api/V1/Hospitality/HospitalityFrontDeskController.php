<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityFrontDeskService;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HospitalityFrontDeskController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityFrontDeskService $frontDesk,
    ) {}

    public function arrivals(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);

        return response()->json([
            'data' => $this->frontDesk->arrivals($org, $request->input('date')),
        ]);
    }

    public function departures(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);

        return response()->json([
            'data' => $this->frontDesk->departures($org, $request->input('date')),
        ]);
    }

    public function inHouse(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);

        return response()->json([
            'data' => $this->frontDesk->inHouse($org),
        ]);
    }

    public function checkIn(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'reservation_id' => ['nullable', 'integer'],
            'guest_name' => ['nullable', 'string', 'max:160'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
            'room_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->frontDesk->checkIn($org, $request->user(), $data), 201);
    }

    public function checkOut(Request $request, int $folioId)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'allow_balance' => ['nullable', 'boolean'],
        ]);

        return response()->json(
            $this->frontDesk->checkOut($org, $request->user(), $folioId, (bool) ($data['allow_balance'] ?? false))
        );
    }

    public function checkOutRoom(Request $request, int $roomId)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);

        return response()->json($this->frontDesk->checkOutRoom($org, $roomId));
    }

    public function assignRoom(Request $request, int $folioId)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
        ]);

        return response()->json(
            $this->frontDesk->assignRoom($org, $folioId, (int) $data['room_id'])
        );
    }

    public function reassignOccupiedRoom(Request $request, int $roomId)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
        ]);

        return response()->json(
            $this->frontDesk->reassignOccupiedRoom($org, $roomId, (int) $data['room_id'])
        );
    }

    protected function assertEnabled(Organization $org): void
    {
        if (! HospitalityServices::enabled($org, 'front_desk')) {
            throw ValidationException::withMessages([
                'service' => ['Front desk is not enabled for this organization.'],
            ]);
        }
    }

    protected function org(Request $request): Organization
    {
        return $this->erp->resolveOrganization($request);
    }
}
