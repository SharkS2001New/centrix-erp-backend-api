<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityFolioService;
use App\Services\Hospitality\HospitalityServices;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HospitalityFolioController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityFolioService $folios,
    ) {}

    public function index(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);

        return response()->json($this->folios->list($org, [
            'status' => $request->input('status'),
            'q' => $request->input('q'),
            'per_page' => $request->input('per_page', 50),
        ]));
    }

    public function show(Request $request, int $id)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $folio = $this->folios->find($org, $id);

        return response()->json(['folio' => $this->folios->toArray($folio, true)]);
    }

    public function store(Request $request)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:160'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
            'room_id' => ['nullable', 'integer'],
        ]);
        $folio = $this->folios->open(
            $org,
            $request->user(),
            $data['guest_name'],
            $data['guest_phone'] ?? null,
            isset($data['room_id']) ? (int) $data['room_id'] : null,
            $request->user()?->branch_id ? (int) $request->user()->branch_id : null,
        );

        return response()->json(['folio' => $this->folios->toArray($folio, true)], 201);
    }

    public function addCharge(Request $request, int $id)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'charge_type' => ['required', 'in:room,fnb,minibar,laundry,other'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $folio = $this->folios->find($org, $id);
        $folio = $this->folios->addCharge(
            $folio,
            $request->user(),
            $data['charge_type'],
            $data['description'],
            (float) $data['amount'],
            (float) ($data['vat_amount'] ?? 0),
        );

        return response()->json(['folio' => $this->folios->toArray($folio, true)]);
    }

    public function addPayment(Request $request, int $id)
    {
        $org = $this->org($request);
        $this->assertEnabled($org);
        $data = $request->validate([
            'method_code' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $folio = $this->folios->find($org, $id);
        $folio = $this->folios->addPayment(
            $folio,
            $request->user(),
            $data['method_code'],
            (float) $data['amount'],
            $data['reference'] ?? null,
        );

        return response()->json(['folio' => $this->folios->toArray($folio, true)]);
    }

    public function openList(Request $request)
    {
        $org = $this->org($request);
        if (! HospitalityServices::enabled($org, 'folios') && ! HospitalityServices::enabled($org, 'room_charge')) {
            return response()->json(['data' => []]);
        }
        $result = $this->folios->list($org, ['status' => 'open', 'per_page' => 100]);

        return response()->json(['data' => $result['data'] ?? []]);
    }

    protected function assertEnabled(Organization $org): void
    {
        if (! HospitalityServices::enabled($org, 'folios')) {
            throw ValidationException::withMessages([
                'service' => ['Guest folios are not enabled for this organization.'],
            ]);
        }
    }

    protected function org(Request $request): Organization
    {
        return $this->erp->resolveOrganization($request);
    }
}
