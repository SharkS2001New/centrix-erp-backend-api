<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sales\LegacyReturnService;
use Illuminate\Http\Request;

class LegacyReturnsController extends Controller
{
    public function __construct(protected LegacyReturnService $service) {}

    public function index(Request $request)
    {
        $paginator = $this->service->paginate($request->user(), $request->only([
            'status',
            'sale_id',
            'from_date',
            'to_date',
            'q',
            'per_page',
        ]));

        return response()->json($paginator);
    }

    public function show(Request $request, string $id)
    {
        return response()->json(
            $this->service->findForUser($request->user(), (int) $id),
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sale_id' => 'required|integer|exists:sales,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'customer_num' => 'nullable|integer|exists:customers,customer_num',
            'return_date' => 'nullable|date',
            'refund_method' => 'nullable|string|max:45',
            'reason' => 'nullable|string|max:200',
            'notes' => 'nullable|string',
            'stock_location' => 'nullable|in:shop,store',
            'kra_original_invoice_number' => 'nullable|string|max:64',
            'auto_approve' => 'sometimes|boolean',
            'full_return' => 'sometimes|boolean',
            'lines' => 'required_without:full_return|array|min:1',
            'lines.*.product_code' => 'required|string',
            'lines.*.return_qty' => 'required|numeric|min:0',
            'lines.*.quantity_sold' => 'nullable|numeric|min:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'nullable|numeric|min:0',
            'lines.*.sale_item_id' => 'nullable|integer',
            'lines.*.product_name' => 'nullable|string|max:200',
            'lines.*.uom' => 'nullable|string|max:45',
            'lines.*.line_no' => 'nullable|integer',
        ]);

        $return = $this->service->create($request->user(), $data);

        return response()->json($return, 201);
    }

    public function approve(Request $request, string $id)
    {
        $return = $this->service->findForUser($request->user(), (int) $id);
        $approved = $this->service->approve($return, $request->user());

        return response()->json($approved);
    }
}
