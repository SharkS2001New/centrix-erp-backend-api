<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityCheckService;
use App\Services\Hospitality\HospitalityPosCatalogService;
use App\Services\Hospitality\HospitalityPosSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HospitalityPosController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityPosCatalogService $catalogService,
        protected HospitalityCheckService $checkService,
    ) {}

    public function catalog(Request $request)
    {
        $user = $request->user();
        $org = $this->requireOrg($user);

        return response()->json($this->catalogService->catalog($org, $user, $request));
    }

    public function settings(Request $request)
    {
        $org = $this->requireOrg($request->user());

        return response()->json([
            'hotel_pos_grid_columns' => HospitalityPosSettings::gridColumnsForOrganization($org),
        ]);
    }

    public function openCheck(Request $request)
    {
        $user = $request->user();
        $org = $this->requireOrg($user);
        $data = $request->validate([
            'outlet_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $check = $this->checkService->openCheck(
            $org,
            $user,
            isset($data['branch_id']) ? (int) $data['branch_id'] : ($user->branch_id ? (int) $user->branch_id : null),
            isset($data['outlet_id']) ? (int) $data['outlet_id'] : null,
        );

        return response()->json(['check' => $this->checkService->toArray($check)], 201);
    }

    public function showCheck(Request $request, int $checkId)
    {
        $org = $this->requireOrg($request->user());
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function addLine(Request $request, int $checkId)
    {
        $org = $this->requireOrg($request->user());
        $data = $request->validate([
            'product_code' => ['required', 'string', 'max:64'],
            'qty' => ['nullable', 'numeric', 'min:0.0001'],
        ]);
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        $check = $this->checkService->addProductLine(
            $check,
            (string) $data['product_code'],
            isset($data['qty']) ? (float) $data['qty'] : 1,
        );

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function updateLine(Request $request, int $checkId, int $lineId)
    {
        $org = $this->requireOrg($request->user());
        $data = $request->validate([
            'qty' => ['required', 'numeric'],
        ]);
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        $check = $this->checkService->updateLineQty($check, $lineId, (float) $data['qty']);

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function removeLine(Request $request, int $checkId, int $lineId)
    {
        $org = $this->requireOrg($request->user());
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        $check = $this->checkService->removeLine($check, $lineId);

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function clear(Request $request, int $checkId)
    {
        $org = $this->requireOrg($request->user());
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        $check = $this->checkService->clearLines($check);

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function hold(Request $request, int $checkId)
    {
        $org = $this->requireOrg($request->user());
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        $check = $this->checkService->hold($check);

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function resume(Request $request, int $checkId)
    {
        $org = $this->requireOrg($request->user());
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        $check = $this->checkService->resume($check);

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function settle(Request $request, int $checkId)
    {
        $user = $request->user();
        $org = $this->requireOrg($user);
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'string', 'in:CASH'],
        ]);
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        $check = $this->checkService->settleCash(
            $check,
            $user,
            array_key_exists('amount', $data) && $data['amount'] !== null ? (float) $data['amount'] : null,
        );

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function voidCheck(Request $request, int $checkId)
    {
        $org = $this->requireOrg($request->user());
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        $check = $this->checkService->voidOpen($check);

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function held(Request $request)
    {
        $org = $this->requireOrg($request->user());
        $outletId = $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null;
        $checks = $this->checkService->listHeld((int) $org->id, $outletId);

        return response()->json([
            'checks' => array_map(fn ($c) => $this->checkService->toArray($c), $checks),
        ]);
    }

    protected function requireOrg($user): Organization
    {
        $org = $this->erp->organizationForUser($user);
        if (! $org) {
            throw ValidationException::withMessages(['organization' => ['No organization context.']]);
        }

        return $org;
    }
}
