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

    /**
     * List standalone credit notes (without stock return).
     *
     * Pending/rejected rows live on customer_returns (return_kind=credit_note) until
     * approval creates the credit_notes document — include those so they appear under
     * Credit notes and can go through approval.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (! Schema::hasColumn('customer_returns', 'return_kind')) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 25,
                'total' => 0,
            ]);
        }

        $relations = ['sale', 'customer', 'returnedByUser', 'approvedByUser', 'rejectedByUser'];
        if (Schema::hasTable('credit_notes')) {
            $relations[] = 'creditNote';
        }

        $query = CustomerReturn::query()
            ->with($relations)
            ->where('organization_id', $user->organization_id)
            ->where('return_kind', 'credit_note');

        app(UserAccessService::class)->scopeBranchIfLimited($query, $user);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
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
                    ->orWhereHas('creditNote', fn ($cn) => $cn->where('credit_note_no', 'like', "%{$q}%"))
                    ->orWhereHas('sale', fn ($s) => $s->where('order_num', 'like', "%{$q}%"))
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$q}%"));
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        $paginator->through(function (CustomerReturn $return) use ($user) {
            return $this->serializeListRow($return, $user);
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

        return response()->json(
            $this->serializeListRow($return, $request->user()),
            201,
        );
    }

    public function show(string $id)
    {
        $resolved = $this->resolveCreditNoteResource($id);
        $return = $resolved['return'];
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

        return response()->json($this->serializeListRow($return, request()->user()));
    }

    public function update(Request $request, string $id)
    {
        $resolved = $this->resolveCreditNoteResource($id);
        $return = $resolved['return'];
        abort_unless($return && $return->return_kind === 'credit_note', 404);

        $data = $request->validate([
            'sale_id' => 'sometimes|integer|exists:sales,id',
            'customer_num' => 'nullable|integer|exists:customers,customer_num',
            'credit_date' => 'nullable|date',
            'return_date' => 'nullable|date',
            'refund_method' => 'nullable|string|max:45',
            'reason' => 'required|string|min:3|max:200',
            'notes' => 'nullable|string',
            'total_amount' => 'nullable|numeric|min:0.01',
            'lines' => 'nullable|array',
            'lines.*.product_code' => 'required_with:lines|string',
            'lines.*.amount' => 'required_with:lines|numeric|min:0.01',
            'lines.*.sale_item_id' => 'nullable|integer',
            'lines.*.product_name' => 'nullable|string|max:200',
            'lines.*.uom' => 'nullable|string|max:45',
            'lines.*.line_no' => 'nullable|integer',
        ]);

        if (array_key_exists('lines', $data) || array_key_exists('total_amount', $data)) {
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
        }

        if (isset($data['credit_date'])) {
            $data['return_date'] = $data['credit_date'];
        } elseif (isset($data['return_date'])) {
            $data['credit_date'] = $data['return_date'];
        }

        $updated = $this->service->update($return, $data);

        return response()->json(
            $this->serializeListRow(
                $updated->load(['lines', 'sale', 'customer', 'returnedByUser', 'creditNote']),
                $request->user(),
            ),
        );
    }

    public function approve(Request $request, string $id)
    {
        $resolved = $this->resolveCreditNoteResource($id);
        $return = $resolved['return'];
        abort_unless($return && $return->return_kind === 'credit_note', 404);

        $approved = $this->service->approve($return, $request->user());

        return response()->json(
            $this->serializeListRow($approved->load('creditNote'), $request->user()),
        );
    }

    public function reject(Request $request, string $id)
    {
        $resolved = $this->resolveCreditNoteResource($id);
        $return = $resolved['return'];
        abort_unless($return && $return->return_kind === 'credit_note', 404);

        $data = $request->validate([
            'reason' => 'nullable|string|min:3|max:500',
        ]);

        $rejected = $this->service->reject($return, $request->user(), $data['reason'] ?? null);

        return response()->json($this->serializeListRow($rejected, $request->user()));
    }

    public function destroy(Request $request, string $id)
    {
        $resolved = $this->resolveCreditNoteResource($id);
        $return = $resolved['return'];
        abort_unless($return && $return->return_kind === 'credit_note', 404);

        $this->service->deleteReturn($return, $request->user());

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array{note: ?CreditNote, return: CustomerReturn}
     */
    protected function resolveCreditNoteResource(string $id): array
    {
        $user = request()->user();

        if (Schema::hasTable('credit_notes')) {
            $note = CreditNote::query()
                ->with(['customerReturn', 'sale', 'customerReturn.customer'])
                ->where('organization_id', $user->organization_id)
                ->whereHas('customerReturn', fn ($inner) => $inner->where('return_kind', 'credit_note'))
                ->find($id);

            if ($note) {
                app(UserAccessService::class)->assertBranchAccess($user, (int) $note->branch_id);
                $return = $note->customerReturn;
                abort_unless($return, 404);

                return ['note' => $note, 'return' => $return];
            }
        }

        $return = CustomerReturn::query()
            ->with(['creditNote', 'sale', 'customer'])
            ->where('organization_id', $user->organization_id)
            ->where('return_kind', 'credit_note')
            ->find($id);

        abort_unless($return, 404);
        app(UserAccessService::class)->assertBranchAccess($user, (int) $return->branch_id);

        return ['note' => $return->creditNote, 'return' => $return];
    }

    /**
     * Shape a credit-note-kind customer return for the Credit notes UI.
     * Pending rows use the return id until approval creates the credit_notes document.
     */
    protected function serializeListRow(CustomerReturn $return, $user): array
    {
        $this->service->withActionFlags($return, $user);
        $note = $return->relationLoaded('creditNote') ? $return->creditNote : $return->creditNote()->first();
        $pendingOnly = $note === null;
        $rawDate = $note?->credit_date ?? $return->return_date;
        if ($rawDate instanceof \DateTimeInterface) {
            $creditDate = $rawDate->format('Y-m-d');
        } else {
            $creditDate = $rawDate ? (string) $rawDate : null;
        }

        $returnedBy = $return->relationLoaded('returnedByUser') ? $return->returnedByUser : null;
        $approvedBy = $return->relationLoaded('approvedByUser') ? $return->approvedByUser : null;
        $lines = $return->relationLoaded('lines') ? $return->lines : [];

        return [
            // Prefer credit_notes.id when issued; otherwise the pending return id.
            'id' => $pendingOnly ? (int) $return->id : (int) $note->id,
            'customer_return_id' => (int) $return->id,
            'pending_return_only' => $pendingOnly,
            'credit_note_no' => $note?->credit_note_no ?? $return->return_no,
            'credit_date' => $creditDate,
            'total_amount' => $note?->total_amount ?? $return->total_amount,
            'refund_method' => $note?->refund_method ?? $return->refund_method,
            'reason' => $note?->reason ?? $return->reason,
            'notes' => $note?->notes ?? $return->notes,
            'sale_id' => $return->sale_id,
            'branch_id' => $return->branch_id,
            'customer_num' => $return->customer_num,
            'organization_id' => $return->organization_id,
            'return_kind' => $return->return_kind,
            'return_no' => $return->return_no,
            'kra_status' => $note?->kra_status,
            'kra_cu_inv_no' => $note?->kra_cu_inv_no,
            'kra_invoice_number' => $note?->kra_invoice_number,
            'kra_receipt_signature' => $note?->kra_receipt_signature,
            'kra_signature_link' => $note?->kra_signature_link,
            'kra_serial_number' => $note?->kra_serial_number,
            'kra_timestamp' => $note?->kra_timestamp,
            'kra_refund_reason_code' => $note?->kra_refund_reason_code,
            'kra_relevant_invoice_number' => $note?->kra_relevant_invoice_number,
            'status' => $return->status,
            'can_approve' => (bool) ($return->can_approve ?? false),
            'can_reject' => (bool) ($return->can_reject ?? false),
            'can_delete' => (bool) ($return->can_delete ?? false),
            'can_edit' => (bool) ($return->can_edit ?? false),
            'can_print' => true,
            'lines' => $lines,
            'processed_by_name' => $approvedBy?->full_name ?? $approvedBy?->username
                ?? $returnedBy?->full_name ?? $returnedBy?->username,
            'returned_by_user' => $returnedBy,
            'approved_by_user' => $approvedBy,
            'sale' => $return->sale,
            'customer' => $return->customer,
            'customer_return' => $return,
        ];
    }
}
