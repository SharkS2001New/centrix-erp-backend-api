<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerReturn;
use App\Models\Sale;
use App\Services\Auth\UserAccessService;
use App\Services\Sales\CustomerReturnService;
use Illuminate\Http\Request;

class CustomerReturnController extends Controller
{
    public function __construct(protected CustomerReturnService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $query = CustomerReturn::query()
            ->with(['lines.product.unit', 'sale', 'customer', 'returnedByUser', 'creditNote'])
            ->where('organization_id', $user->organization_id)
            ->where(function ($inner) {
                $inner->where('return_kind', 'standard')->orWhereNull('return_kind');
            });

        app(UserAccessService::class)->scopeBranchIfLimited($query, $user);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->input('branch_id'));
        }

        if ($request->filled('sale_id')) {
            $query->where('sale_id', $request->input('sale_id'));
        }

        if ($request->filled('customer_num')) {
            $query->where('customer_num', $request->input('customer_num'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('return_date', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('return_date', '<=', $request->input('to_date'));
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('return_no', 'like', "%{$q}%")
                    ->orWhereHas('sale', fn ($s) => $s->where('order_num', 'like', "%{$q}%"))
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$q}%"));
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sale_id' => 'nullable|integer|exists:sales,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'customer_num' => 'nullable|integer|exists:customers,customer_num',
            'return_date' => 'nullable|date',
            'refund_method' => 'nullable|string|max:45',
            'reason' => 'nullable|string|max:200',
            'notes' => 'nullable|string',
            'stock_location' => 'nullable|in:shop,store',
            'auto_approve' => 'sometimes|boolean',
            'lines' => 'required|array|min:1',
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

        if (! empty($data['sale_id'])) {
            $sale = Sale::with('items')->findOrFail($data['sale_id']);
            if ($sale->organization_id !== $request->user()->organization_id) {
                abort(403);
            }
            $data['customer_num'] = $data['customer_num'] ?? $sale->customer_num;
            $data['branch_id'] = $data['branch_id'] ?? $sale->branch_id;
        }

        $return = $this->service->create($request->user(), $data);

        return response()->json($return, 201);
    }

    public function show(string $id)
    {
        $return = $this->findForUser($id);

        return response()->json(
            $return->load([
                'lines.product.unit',
                'sale.items.product.unit',
                'customer',
                'returnedByUser',
                'approvedByUser',
                'rejectedByUser',
                'creditNote',
            ]),
        );
    }

    public function update(Request $request, string $id)
    {
        $return = $this->findForUser($id);

        $data = $request->validate([
            'sale_id' => 'sometimes|nullable|integer|exists:sales,id',
            'customer_num' => 'sometimes|nullable|integer|exists:customers,customer_num',
            'return_date' => 'sometimes|date',
            'refund_method' => 'sometimes|string|max:45',
            'reason' => 'sometimes|nullable|string|max:200',
            'notes' => 'sometimes|nullable|string',
            'stock_location' => 'sometimes|in:shop,store',
            'lines' => 'sometimes|array|min:1',
            'lines.*.product_code' => 'required_with:lines|string',
            'lines.*.return_qty' => 'required_with:lines|numeric|min:0',
            'lines.*.quantity_sold' => 'nullable|numeric|min:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'nullable|numeric|min:0',
            'lines.*.sale_item_id' => 'nullable|integer',
            'lines.*.product_name' => 'nullable|string|max:200',
            'lines.*.uom' => 'nullable|string|max:45',
            'lines.*.line_no' => 'nullable|integer',
        ]);

        $updated = $this->service->update($return, $data);

        return response()->json($updated);
    }

    public function destroy(Request $request, string $id)
    {
        $return = $this->findForUser($id);
        $this->service->deleteReturn($return, $request->user());

        return response()->json(['deleted' => true]);
    }

    public function approve(Request $request, string $id)
    {
        $return = $this->findForUser($id);
        $approved = $this->service->approve($return, $request->user());

        return response()->json($approved);
    }

    public function reject(Request $request, string $id)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $return = $this->findForUser($id);
        $rejected = $this->service->reject($return, $request->user(), $data['reason'] ?? null);

        return response()->json($rejected);
    }

    public function saleLines(Request $request, string $saleId)
    {
        $sale = Sale::with(['items.product.unit'])->findOrFail($saleId);
        if ($sale->organization_id !== $request->user()->organization_id) {
            abort(403);
        }

        return response()->json([
            'sale' => $sale,
            'lines' => $this->service->linesFromSale($sale),
        ]);
    }

    protected function findForUser(string $id): CustomerReturn
    {
        $user = request()->user();
        $query = CustomerReturn::query()
            ->where('organization_id', $user->organization_id);

        app(UserAccessService::class)->scopeBranchIfLimited($query, $user);

        return $query->findOrFail($id);
    }
}
