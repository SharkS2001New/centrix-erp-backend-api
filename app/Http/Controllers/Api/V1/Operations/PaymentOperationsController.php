<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesBranchScope;
use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Sales\SalePaymentAllocationService;
use App\Services\Sales\SalePaymentStatusConversionService;
use Illuminate\Http\Request;

class PaymentOperationsController extends Controller
{
    use HandlesBranchScope;

    public function __construct(protected ErpContext $erp) {}

    public function paySale(Request $request, int $saleId)
    {
        $sale = $this->findScopedSale($saleId, $request->user());
        $data = $request->validate([
            'payment_method_id' => 'required_without:payments|integer',
            'amount' => 'required_without:payments|numeric|min:0.01',
            'reference_number' => 'nullable|string',
            'float_session_id' => 'nullable|integer',
            'payments' => 'required_without:payment_method_id|array|min:1',
            'payments.*.payment_method_id' => 'required|integer',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.reference_number' => 'nullable|string',
            'payments.*.float_session_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $sessionId = isset($data['float_session_id']) ? (int) $data['float_session_id'] : null;
        $allocator = app(SalePaymentAllocationService::class);

        if (! empty($data['payments'])) {
            $payments = array_map(static function (array $row) use ($user, $sessionId): array {
                return [
                    'payment_method_id' => (int) $row['payment_method_id'],
                    'amount' => $row['amount'],
                    'reference_number' => $row['reference_number'] ?? null,
                    'float_session_id' => isset($row['float_session_id'])
                        ? (int) $row['float_session_id']
                        : $sessionId,
                    'received_by' => $user->id,
                ];
            }, $data['payments']);

            $sale = $allocator->allocateMany($sale, $payments, $user);
        } else {
            $data['received_by'] = $user->id;
            $sale = $allocator->allocate($sale, $data, $user);
        }

        return response()->json($sale);
    }

    public function convertToPaid(Request $request, int $saleId)
    {
        $sale = $this->findScopedSale($saleId, $request->user());
        $sale = app(SalePaymentStatusConversionService::class)->convertToPaid($sale, $request->user());

        return response()->json($sale);
    }

    public function convertToUnpaid(Request $request, int $saleId)
    {
        $sale = $this->findScopedSale($saleId, $request->user());
        $sale = app(SalePaymentStatusConversionService::class)->convertToUnpaid($sale, $request->user());

        return response()->json($sale);
    }
}
