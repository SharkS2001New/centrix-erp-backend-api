<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ParsesMultipartJsonFields;
use App\Http\Controllers\Controller;
use App\Models\CustomerReturn;
use App\Models\Sale;
use App\Services\Auth\UserAccessService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Returns\ReturnProofService;
use App\Services\Sales\CustomerReturnService;
use App\Support\StoredPublicFile;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class CustomerReturnController extends Controller
{
    use ParsesMultipartJsonFields;

    private const DEFAULT_RANGE_DAYS = 30;

    public function __construct(
        protected CustomerReturnService $service,
        protected ReturnProofService $proofService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        // List rows do not need lines/product graphs — detail/print load those on demand.
        $relations = ['sale', 'customer', 'returnedByUser'];
        if (Schema::hasTable('credit_notes')) {
            $relations[] = 'creditNote';
        }

        $query = CustomerReturn::query()
            ->with($relations)
            ->where('organization_id', $user->organization_id);

        if (Schema::hasColumn('customer_returns', 'return_kind')) {
            $query->where(function ($inner) {
                $inner->where('return_kind', 'standard')->orWhereNull('return_kind');
            });
        }

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

        $hasFrom = $request->filled('from_date');
        $hasTo = $request->filled('to_date');
        $skipDefaultDate = $request->filled('sale_id')
            || $request->filled('customer_num')
            || trim((string) $request->input('q', '')) !== '';

        if (! $hasFrom && ! $hasTo && ! $skipDefaultDate) {
            $to = now()->toDateString();
            $from = Carbon::parse($to)->subDays(self::DEFAULT_RANGE_DAYS - 1)->toDateString();
            $query->whereDate('return_date', '>=', $from)
                ->whereDate('return_date', '<=', $to);
        } else {
            if ($hasFrom) {
                $query->whereDate('return_date', '>=', $request->input('from_date'));
            }
            if ($hasTo) {
                $query->whereDate('return_date', '<=', $request->input('to_date'));
            }
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('return_no', 'like', "%{$q}%")
                    ->orWhereHas('sale', fn ($s) => $s->where('order_num', 'like', "%{$q}%"))
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$q}%"));
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $paginator->through(fn (CustomerReturn $return) => $this->service->withActionFlags($return, $user));

        return response()->json($paginator);
    }

    public function store(Request $request)
    {
        $this->decodeMultipartJsonFields($request, ['lines']);

        $data = $request->validate([
            'sale_id' => 'nullable|integer|exists:sales,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'customer_num' => 'nullable|integer|exists:customers,customer_num',
            'return_date' => 'nullable|date',
            'refund_method' => 'nullable|string|max:45',
            'reason' => 'required|string|min:3|max:200',
            'notes' => 'nullable|string',
            'stock_location' => 'nullable|in:shop,store',
            'auto_approve' => 'sometimes|boolean',
            'proof' => ReturnProofService::fileRules(),
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

        $return = $this->service->create($request->user(), $data, $request->file('proof'));

        return response()->json($this->service->withActionFlags($return, $request->user()), 201);
    }

    public function show(string $id)
    {
        $return = $this->findForUser($id);

        $relations = [
            'lines.product.unit',
            'sale.items.product.unit',
            'customer',
            'returnedByUser',
            'approvedByUser',
            'rejectedByUser',
        ];
        if (Schema::hasTable('credit_notes')) {
            $relations[] = 'creditNote';
        }

        return response()->json(
            $this->service->withActionFlags($return->load($relations), request()->user()),
        );
    }

    public function update(Request $request, string $id)
    {
        $return = $this->findForUser($id);
        $this->decodeMultipartJsonFields($request, ['lines']);

        $data = $request->validate([
            'sale_id' => 'sometimes|nullable|integer|exists:sales,id',
            'customer_num' => 'sometimes|nullable|integer|exists:customers,customer_num',
            'return_date' => 'sometimes|date',
            'refund_method' => 'sometimes|string|max:45',
            'reason' => 'sometimes|required|string|min:3|max:200',
            'notes' => 'sometimes|nullable|string',
            'stock_location' => 'sometimes|in:shop,store',
            'proof' => ReturnProofService::fileRules(),
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

        $updated = $this->service->update($return, $data, $request->file('proof'));

        return response()->json($this->service->withActionFlags($updated, $request->user()));
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
        $user = $request->user();
        $approved = $this->service->approve($return, $user);

        app(ActionRequestService::class)->markResolvedFromDomain(
            'customer_return',
            'customer_return',
            (int) $approved->id,
            'approved',
            $user,
        );

        return response()->json($this->service->withActionFlags($approved, $user));
    }

    public function reject(Request $request, string $id)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $return = $this->findForUser($id);
        $user = $request->user();
        $rejected = $this->service->reject($return, $user, $data['reason'] ?? null);

        app(ActionRequestService::class)->markResolvedFromDomain(
            'customer_return',
            'customer_return',
            (int) $rejected->id,
            'rejected',
            $user,
            $data['reason'] ?? null,
        );

        return response()->json($this->service->withActionFlags($rejected, $user));
    }

    public function proofFile(string $id)
    {
        $return = $this->findForUser($id);

        if (! is_string($return->proof_file_path ?? null) || ! StoredPublicFile::exists($return->proof_file_path)) {
            abort(Response::HTTP_NOT_FOUND, 'Proof file not found.');
        }

        return StoredPublicFile::response($return->proof_file_path, $return->proof_file_mime_type ?: 'application/octet-stream', [
            'Content-Disposition' => 'inline; filename="'.($return->proof_file_name ?: 'proof').'"',
        ]);
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
