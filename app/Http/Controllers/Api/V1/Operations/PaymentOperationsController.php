<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesBranchScope;
use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Sales\SalePaymentAllocationService;
use Illuminate\Http\Request;

class PaymentOperationsController extends Controller
{
    use HandlesBranchScope;

    public function __construct(protected ErpContext $erp) {}

    public function paySale(Request $request, int $saleId)
    {
        $sale = $this->findScopedSale($saleId, $request->user());
        $data = $request->validate([
            'payment_method_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'reference_number' => 'nullable|string',
            'float_session_id' => 'nullable|integer',
        ]);
        $data['received_by'] = $request->user()->id;

        $sale = app(SalePaymentAllocationService::class)->allocate(
            $sale,
            $data,
            $request->user(),
        );

        return response()->json($sale);
    }
}
