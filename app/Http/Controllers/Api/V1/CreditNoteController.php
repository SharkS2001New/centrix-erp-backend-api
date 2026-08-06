<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\CustomerReturn;
use App\Services\Auth\UserAccessService;
use App\Services\Sales\CustomerReturnService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CreditNoteController extends Controller
{
    private const DEFAULT_RANGE_DAYS = 30;

    public function __construct(
        protected CustomerReturnService $service,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $query = CreditNote::query()
            ->with([
                'customerReturn.sale',
                'customerReturn.customer',
                'customerReturn.returnedByUser',
                'sale',
            ])
            ->where('organization_id', $user->organization_id)
            ->whereHas('customerReturn', function ($inner) {
                $inner->where('return_kind', 'credit_note');
            });

        app(UserAccessService::class)->scopeBranchIfLimited($query, $user, 'branch_id');

        if ($request->filled('status')) {
            $status = (string) $request->input('status');
            $query->whereHas('customerReturn', fn ($inner) => $inner->where('status', $status));
        }

        if ($request->filled('sale_id')) {
            $query->where('sale_id', (int) $request->input('sale_id'));
        }

        if ($request->filled('customer_num')) {
            $query->where('customer_num', (int) $request->input('customer_num'));
        }

        $hasFrom = $request->filled('from_date');
        $hasTo = $request->filled('to_date');
        $skipDefaultDate = $request->filled('sale_id')
            || $request->filled('customer_num')
            || trim((string) $request->input('q', '')) !== '';

        if (! $hasFrom && ! $hasTo && ! $skipDefaultDate) {
            $to = now()->toDateString();
            $from = Carbon::parse($to)->subDays(self::DEFAULT_RANGE_DAYS - 1)->toDateString();
            $query->whereDate('credit_date', '>=', $from)
                ->whereDate('credit_date', '<=', $to);
        } else {
            if ($hasFrom) {
                $query->whereDate('credit_date', '>=', $request->input('from_date'));
            }
            if ($hasTo) {
                $query->whereDate('credit_date', '<=', $request->input('to_date'));
            }
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('credit_note_no', 'like', "%{$q}%")
                    ->orWhereHas('customerReturn', fn ($cr) => $cr->where('return_no', 'like', "%{$q}%"))
                    ->orWhereHas('sale', fn ($s) => $s->where('order_num', 'like', "%{$q}%"))
                    ->orWhereHas('customerReturn.customer', fn ($c) => $c->where('customer_name', 'like', "%{$q}%"));
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        $paginator->through(function (CreditNote $note) use ($user) {
            $return = $note->customerReturn;
            if ($return) {
                $this->service->withActionFlags($return, $user);
            }
            $note->setAttribute('customer_return', $return);
            $note->setAttribute('status', $return?->status);
            $note->setAttribute('can_approve', $return?->can_approve ?? false);
            $note->setAttribute('can_reject', $return?->can_reject ?? false);
            $note->setAttribute('can_delete', $return?->can_delete ?? false);
            $note->setAttribute('can_print', true);

            return $note;
        });

        return response()->json($paginator);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sale_id' => 'required|integer|exists:sales,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'customer_num' => 'nullable|integer|exists:customers,customer_num',
            'credit_date' => 'nullable|date',
            'return_date' => 'nullable|date',
            'refund_method' => 'nullable|string|max:45',
            'reason' => 'required|string|min:3|max:200',
            'notes' => 'nullable|string',
            'auto_approve' => 'sometimes|boolean',
            // Amount-only credits (price difference / underpayment) need no product lines.
            'total_amount' => 'nullable|numeric|min:0.01',
            'lines' => 'nullable|array',
            'lines.*.product_code' => 'required_with:lines|string',
            'lines.*.amount' => 'required_with:lines|numeric|min:0.01',
            'lines.*.sale_item_id' => 'nullable|integer',
            'lines.*.product_name' => 'nullable|string|max:200',
            'lines.*.uom' => 'nullable|string|max:45',
            'lines.*.line_no' => 'nullable|integer',
        ]);

        $lines = $data['lines'] ?? [];
        $hasLines = is_array($lines) && collect($lines)->contains(
            fn ($line) => round((float) ($line['amount'] ?? 0), 2) > 0,
        );
        $hasTotal = isset($data['total_amount']) && round((float) $data['total_amount'], 2) > 0;

        if (! $hasLines && ! $hasTotal) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'total_amount' => 'Enter a credit amount, or add at least one product line.',
            ]);
        }

        $data['return_date'] = $data['credit_date'] ?? $data['return_date'] ?? now()->toDateString();
        $return = $this->service->createCreditNote($request->user(), $data);

        return response()->json($this->service->withActionFlags($return, $request->user()), 201);
    }

    public function show(string $id)
    {
        $note = $this->findForUser($id);
        $return = $note->customerReturn;
        if ($return) {
            $return->load([
                'lines.product.unit',
                'sale.items.product.unit',
                'customer',
                'returnedByUser',
                'approvedByUser',
                'rejectedByUser',
                'creditNote',
            ]);
            $this->service->withActionFlags($return, request()->user());
        }
        $note->setAttribute('customer_return', $return);

        return response()->json($note);
    }

    public function approve(Request $request, string $id)
    {
        $note = $this->findForUser($id);
        $return = $note->customerReturn;
        abort_unless($return && $return->return_kind === 'credit_note', 404);

        $approved = $this->service->approve($return, $request->user());

        return response()->json($this->service->withActionFlags($approved->load('creditNote'), $request->user()));
    }

    public function reject(Request $request, string $id)
    {
        $note = $this->findForUser($id);
        $return = $note->customerReturn;
        abort_unless($return && $return->return_kind === 'credit_note', 404);

        $data = $request->validate([
            'reason' => 'nullable|string|min:3|max:500',
        ]);

        $rejected = $this->service->reject($return, $request->user(), $data['reason'] ?? null);

        return response()->json($this->service->withActionFlags($rejected, $request->user()));
    }

    public function destroy(Request $request, string $id)
    {
        $note = $this->findForUser($id);
        $return = $note->customerReturn;
        abort_unless($return && $return->return_kind === 'credit_note', 404);

        $this->service->deleteReturn($return, $request->user());

        return response()->json(['deleted' => true]);
    }

    protected function findForUser(string $id): CreditNote
    {
        if (! Schema::hasTable('credit_notes')) {
            abort(404);
        }

        $user = request()->user();
        $note = CreditNote::query()
            ->with(['customerReturn', 'sale', 'customerReturn.customer'])
            ->where('organization_id', $user->organization_id)
            ->whereHas('customerReturn', fn ($inner) => $inner->where('return_kind', 'credit_note'))
            ->findOrFail($id);

        app(UserAccessService::class)->assertBranchAccess($user, (int) $note->branch_id);

        return $note;
    }
}
