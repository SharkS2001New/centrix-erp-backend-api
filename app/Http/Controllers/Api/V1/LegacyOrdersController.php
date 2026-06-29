<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sales\LegacyOrderService;
use App\Services\Sales\LegacyReturnService;
use Illuminate\Http\Request;

class LegacyOrdersController extends Controller
{
    public function __construct(
        protected LegacyOrderService $orders,
        protected LegacyReturnService $returns,
    ) {}

    public function index(Request $request)
    {
        $paginator = $this->orders->paginate($request->user(), $request->only([
            'from_date',
            'to_date',
            'customer_name',
            'q',
            'has_returns',
            'min_order_total',
            'max_order_total',
            'order_total',
            'per_page',
        ]));

        return response()->json($paginator);
    }

    public function show(Request $request, string $id)
    {
        $sale = $this->orders->findForUser($request->user(), (int) $id);
        if ($request->boolean('for_print')) {
            $sale = $this->orders->prepareSaleForPrint($sale);
        }
        $kraHint = $this->returns->kraInvoiceHintForSale($sale);

        return response()->json(array_merge($sale->toArray(), [
            'kra_invoice_hint' => $kraHint,
        ]));
    }

    public function returnLines(Request $request, string $saleId)
    {
        return response()->json([
            'lines' => $this->returns->linesFromSale($request->user(), (int) $saleId),
            'kra_invoice_hint' => $this->returns->kraInvoiceHintForSale(
                $this->orders->findForUser($request->user(), (int) $saleId),
            ),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $this->orders->deleteForUser($request->user(), (int) $id);

        return response()->json([
            'message' => 'Legacy order deleted. You can materialize it again from the legacy archive if needed.',
        ]);
    }
}
