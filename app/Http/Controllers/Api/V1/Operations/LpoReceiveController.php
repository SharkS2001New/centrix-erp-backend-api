<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockReceiveRequest;
use App\Models\LpoTxn;
use App\Models\StockReceipt;
use App\Models\User;
use App\Services\Accounting\PurchaseReceiveJournalService;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\DB;

class LpoReceiveController extends Controller
{
    use HandlesInventory;

    public function __construct(protected ErpContext $erp) {}

    public function store(StockReceiveRequest $request)
    {
        $data = $request->validated();
        if (empty($data['stock_location'])) {
            $orgId = (int) ($request->user()?->organization_id ?? 0);
            $procurement = \App\Services\Purchasing\ProcurementSettingsResolver::forOrganizationId($orgId);
            $data['stock_location'] = $procurement['default_receive_location'] ?? 'store';
        }

        $receipt = $this->receiveStockLine($data, $request->user());

        $gate = $this->erp->gateForUser($request->user());
        app(PurchaseReceiveJournalService::class)->postIfEnabled($receipt, $request->user(), $gate);

        return response()->json($receipt, 201);
    }

    protected function receiveStockLine(array $data, User $user): StockReceipt
    {
        return DB::transaction(function () use ($data, $user) {
            $location = $data['stock_location'] ?? 'store';
            $qty = (float) $data['units_received'];

            $receipt = StockReceipt::create([
                'product_code' => $data['product_code'],
                'branch_id' => $data['branch_id'],
                'organization_id' => $user->organization_id,
                'units_received' => $qty,
                'stock_location' => $location,
                'invoice_number' => $data['invoice_number'] ?? null,
                'cost_price' => $data['cost_price'] ?? null,
                'received_by' => $user->id,
            ]);

            $this->postStockLedger([
                'branch_id' => $data['branch_id'],
                'product_code' => $data['product_code'],
                'stock_location' => $location,
                'transaction_type' => 'PURCHASE',
                'reference_type' => 'stock_receipt',
                'reference_id' => $receipt->id,
                'quantity_change' => $qty,
                'unit_cost' => $data['cost_price'] ?? null,
                'created_by' => $user->id,
            ]);

            if (! empty($data['lpo_txn_id'])) {
                $txn = LpoTxn::find($data['lpo_txn_id']);
                if ($txn) {
                    $txn->received_qty = (float) ($txn->received_qty ?? 0) + $qty;
                    $txn->save();
                }
            }

            return $receipt;
        });
    }
}
