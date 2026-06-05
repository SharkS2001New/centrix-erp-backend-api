<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockReceiveRequest;
use App\Models\LpoMst;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\User;
use App\Models\Uom;
use App\Services\LpoModuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LpoReceiveController extends Controller
{
    use HandlesInventory;

    public function __construct(
        protected LpoModuleService $lpoModule,
    ) {}

    public function store(StockReceiveRequest $request)
    {
        $receipt = $this->receiveStockLine($request->validated(), $request->user());

        return response()->json($receipt, 201);
    }

    protected function receiveStockLine(array $data, User $user): StockReceipt
    {
        return DB::transaction(function () use ($data, $user) {
            $txn = null;
            if (! empty($data['lpo_txn_id'])) {
                $txn = LpoTxn::find($data['lpo_txn_id']);
                if ($txn) {
                    $lpo = LpoMst::query()->whereNull('deleted_at')->where('lpo_no', $txn->lpo_no)->first();
                    if ($lpo && ! $this->lpoModule->canReceive($lpo)) {
                        throw new \InvalidArgumentException(
                            'Stock cannot be received on this purchase order because all items were returned to the supplier.',
                        );
                    }

                    $unitsReceived = (float) $data['units_received'];
                    $factor = $this->productConversionFactor($txn->product_code);
                    $maxReceivable = max(
                        0,
                        (float) $txn->ordered_qty
                            - (float) ($txn->received_qty ?? 0)
                            - $this->lpoModule->returnedQtyForLine($txn),
                    );
                    $maxReceivableBase = $maxReceivable * $factor;
                    if ($unitsReceived > $maxReceivableBase + 0.0001) {
                        throw new \InvalidArgumentException(
                            'Cannot receive more than the remaining quantity on this purchase order line after returns.',
                        );
                    }
                }
            }

            $location = $data['stock_location'] ?? 'store';
            $qty = (float) $data['units_received'];

            $receiptPayload = [
                'product_code' => $data['product_code'],
                'branch_id' => $data['branch_id'],
                'organization_id' => $user->organization_id,
                'units_received' => $qty,
                'stock_location' => $location,
                'invoice_number' => $data['invoice_number'] ?? null,
                'cost_price' => $data['cost_price'] ?? null,
                'received_by' => $user->id,
            ];
            if ($txn && Schema::hasColumn('stock_receipts', 'lpo_no')) {
                $receiptPayload['lpo_no'] = (int) $txn->lpo_no;
            }
            if ($txn && Schema::hasColumn('stock_receipts', 'lpo_txn_id')) {
                $receiptPayload['lpo_txn_id'] = (int) $txn->id;
            }

            $receipt = StockReceipt::create($receiptPayload);

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
                    $factor = $this->productConversionFactor($txn->product_code);
                    $lpoQty = isset($data['pack_qty'])
                        ? (float) $data['pack_qty']
                        : ($factor > 0 ? $qty / $factor : $qty);
                    $txn->received_qty = (float) ($txn->received_qty ?? 0) + $lpoQty;
                    $txn->save();
                    $this->lpoModule->syncReceiveStatus((int) $txn->lpo_no);

                    $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
                    if ($invoiceNumber !== '') {
                        $this->lpoModule->recordSupplierInvoiceFromReceive((int) $txn->lpo_no, $invoiceNumber);
                    }
                }
            }

            if (isset($data['cost_price']) && $data['cost_price'] !== null && $data['cost_price'] !== '') {
                Product::where('product_code', $data['product_code'])->update([
                    'last_cost_price' => (float) $data['cost_price'],
                ]);
            }

            return $receipt;
        });
    }

    protected function productConversionFactor(string $productCode): float
    {
        $product = Product::query()->where('product_code', $productCode)->first();
        if (! $product?->unit_id) {
            return 1.0;
        }

        $factor = Uom::query()->where('id', $product->unit_id)->value('conversion_factor');

        return max(1.0, (float) ($factor ?? 1));
    }
}
