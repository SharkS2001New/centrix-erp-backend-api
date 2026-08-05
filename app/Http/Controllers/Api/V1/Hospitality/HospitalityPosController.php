<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityCheckService;
use App\Services\Hospitality\HospitalityCheckNumberAllocator;
use App\Services\Hospitality\HospitalityPaymentWorkflow;
use App\Services\Hospitality\HospitalityPosCatalogService;
use App\Services\Hospitality\HospitalityPosSettings;
use App\Services\Hospitality\HospitalityServices;
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
        $user = $request->user();
        $org = $this->requireOrg($user);
        $outlet = $this->catalogService->resolveOutletForUser($org, $user, null);
        $channel = HospitalityPosCatalogService::menuChannelForOutlet($outlet);

        return response()->json(array_merge(
            HospitalityPosSettings::forOrganization($org),
            HospitalityServices::presentForOrganization($org),
            HospitalityPaymentWorkflow::presentForOrganization($org),
            [
                'table_pos_enabled' => HospitalityServices::enabled($org, 'table_pos'),
                'floor_tables_enabled' => HospitalityServices::enabled($org, 'floor_tables'),
                'room_charge_enabled' => HospitalityServices::enabled($org, 'room_charge'),
                'outlet' => [
                    'id' => $outlet->id,
                    'code' => $outlet->code,
                    'name' => $outlet->name,
                    'outlet_type' => $outlet->outlet_type,
                    'menu_channel' => $channel,
                    'menu_channel_label' => $channel === 'bar' ? 'Bar' : 'Restaurant',
                ],
            ],
        ));
    }

    public function openCheck(Request $request)
    {
        $user = $request->user();
        $org = $this->requireOrg($user);
        $data = $request->validate([
            'outlet_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'floor_table_id' => ['nullable', 'integer'],
        ]);

        $check = $this->checkService->openCheck(
            $org,
            $user,
            isset($data['branch_id']) ? (int) $data['branch_id'] : ($user->branch_id ? (int) $user->branch_id : null),
            isset($data['outlet_id'])
                ? (int) $data['outlet_id']
                : (($user->hospitality_outlet_id ?? null) ? (int) $user->hospitality_outlet_id : null),
            isset($data['floor_table_id']) ? (int) $data['floor_table_id'] : null,
        );

        return response()->json(['check' => $this->checkService->toArray($check)], 201);
    }

    public function showCheck(Request $request, int $checkId)
    {
        $org = $this->requireOrg($request->user());
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function assignTable(Request $request, int $checkId)
    {
        $org = $this->requireOrg($request->user());
        $data = $request->validate([
            'floor_table_id' => ['nullable', 'integer'],
        ]);
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        $check = $this->checkService->assignFloorTable(
            $check,
            $org,
            isset($data['floor_table_id']) ? (int) $data['floor_table_id'] : null,
        );

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function assignGuest(Request $request, int $checkId)
    {
        $org = $this->requireOrg($request->user());
        $data = $request->validate([
            'guest_name' => ['nullable', 'string', 'max:160'],
        ]);
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        $check = $this->checkService->assignGuestName(
            $check,
            $data['guest_name'] ?? null,
        );

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
        $check = $this->checkService->hold($check, $org);

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
            'method' => ['nullable', 'string', 'max:40'],
            'payments' => ['nullable', 'array', 'min:1'],
            'payments.*.method_code' => ['required_with:payments', 'string', 'max:40'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'payments.*.reference' => ['nullable', 'string', 'max:120'],
            'floor_table_id' => ['nullable', 'integer'],
            'folio_id' => ['nullable', 'integer'],
        ]);
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        if (array_key_exists('floor_table_id', $data) && $data['floor_table_id']) {
            $check = $this->checkService->assignFloorTable($check, $org, (int) $data['floor_table_id']);
        }

        if (! empty($data['payments']) && is_array($data['payments'])) {
            $check = $this->checkService->settleWithPayments(
                $check,
                $user,
                $org,
                $data['payments'],
                null,
                isset($data['folio_id']) ? (int) $data['folio_id'] : null,
            );
        } else {
            $check = $this->checkService->settleCash(
                $check,
                $user,
                $org,
                array_key_exists('amount', $data) && $data['amount'] !== null ? (float) $data['amount'] : null,
            );
        }

        return response()->json(['check' => $this->checkService->toArray($check)]);
    }

    public function save(Request $request, int $checkId)
    {
        $org = $this->requireOrg($request->user());
        $data = $request->validate([
            'floor_table_id' => ['nullable', 'integer'],
        ]);
        $check = $this->checkService->findOwnedCheck($checkId, (int) $org->id);
        if (array_key_exists('floor_table_id', $data) && $data['floor_table_id']) {
            $check = $this->checkService->assignFloorTable($check, $org, (int) $data['floor_table_id']);
        }
        $check = $this->checkService->saveWithoutPayment($check, $org);

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
        return $this->collectible($request);
    }

    public function collectible(Request $request)
    {
        $org = $this->requireOrg($request->user());
        $outletId = $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null;
        $checks = $this->checkService->listCollectible((int) $org->id, $outletId);

        return response()->json([
            'checks' => array_map(fn ($c) => $this->checkService->toArray($c), $checks),
        ]);
    }

    /**
     * Reserve digit check numbers for Hotel POS offline selling (mirrors sales order-numbers/reserve).
     */
    public function reserveCheckNumbers(Request $request)
    {
        $user = $request->user();
        $org = $this->requireOrg($user);
        $data = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:'.HospitalityCheckNumberAllocator::MAX_RESERVE_BLOCK],
        ]);
        $count = (int) ($data['count'] ?? 20);
        $block = app(HospitalityCheckNumberAllocator::class)
            ->reserveBlockForOrganization((int) $org->id, $count);

        return response()->json([
            'organization_id' => (int) $org->id,
            'start' => $block['start'],
            'end' => $block['end'],
            'numbers' => $block['numbers'],
            'count' => count($block['numbers']),
        ]);
    }

    /**
     * Idempotent offline cash check replay: create + lines + settle.
     */
    public function offlineSync(Request $request)
    {
        $user = $request->user();
        $org = $this->requireOrg($user);
        $data = $request->validate([
            'client_check_uuid' => ['required', 'string', 'max:64'],
            'check_number' => ['nullable', 'string', 'max:40'],
            'outlet_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'floor_table_id' => ['nullable', 'integer'],
            'guest_name' => ['nullable', 'string', 'max:160'],
            'offline_order' => ['nullable', 'boolean'],
            'client_completed_at' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_code' => ['required', 'string', 'max:64'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0.0001'],
            'payments' => ['nullable', 'array'],
            'payments.*.method_code' => ['required_with:payments', 'string', 'max:40'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'payments.*.reference' => ['nullable', 'string', 'max:120'],
        ]);

        $check = $this->checkService->ingestOfflineCashCheck(
            $org,
            $user,
            $data['lines'],
            $data['payments'] ?? [],
            $data,
        );

        return response()->json(['check' => $this->checkService->toArray($check)], 201);
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
