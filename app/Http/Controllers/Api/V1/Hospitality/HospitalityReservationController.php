<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityReservationService;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HospitalityReservationController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityReservationService $reservations,
    ) {}

    public function index(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);

        return response()->json($this->reservations->list($org, [
            'status' => $request->input('status'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'overlap_from' => $request->input('overlap_from'),
            'overlap_to' => $request->input('overlap_to'),
            'q' => $request->input('q'),
            'per_page' => $request->input('per_page', 50),
        ]));
    }

    public function store(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:160'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
            'room_type_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'rate_plan_id' => ['nullable', 'integer'],
            'arrival_date' => ['required', 'date'],
            'departure_date' => ['required', 'date', 'after:arrival_date'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $row = $this->reservations->create(
            $org,
            $data,
            $request->user()?->branch_id ? (int) $request->user()->branch_id : null,
        );

        return response()->json(['reservation' => $this->reservations->toArray($row)], 201);
    }

    public function show(Request $request, int $id)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $row = $this->reservations->find($org, $id);

        return response()->json(['reservation' => $this->reservations->toArray($row)]);
    }

    public function update(Request $request, int $id)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'guest_name' => ['sometimes', 'string', 'max:160'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
            'room_type_id' => ['sometimes', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'rate_plan_id' => ['nullable', 'integer'],
            'arrival_date' => ['sometimes', 'date'],
            'departure_date' => ['sometimes', 'date'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'adults' => ['nullable', 'integer', 'min:1', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $row = $this->reservations->update($org, $id, $data);

        return response()->json(['reservation' => $this->reservations->toArray($row)]);
    }

    public function setStatus(Request $request, int $id)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'status' => ['required', 'in:cancelled,no_show'],
        ]);
        $row = $this->reservations->setStatus($org, $id, $data['status']);

        return response()->json(['reservation' => $this->reservations->toArray($row)]);
    }

    protected function assertEnabled(Organization $org): void
    {
        if (! HospitalityServices::enabled($org, 'reservations')) {
            throw ValidationException::withMessages([
                'service' => ['Reservations are not enabled for this organization.'],
            ]);
        }
    }

    protected function org(Request $request): Organization
    {
        return $this->erp->resolveOrganization($request);
    }
}
