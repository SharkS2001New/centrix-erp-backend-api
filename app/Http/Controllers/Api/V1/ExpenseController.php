<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Expense;
use App\Models\TillFloatSession;
use App\Services\Accounting\ExpenseJournalService;
use App\Services\Accounting\ReferenceJournalReversalService;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ExpenseController extends BaseResourceController
{
    public function __construct(protected ErpContext $erp) {}

    protected function modelClass(): string
    {
        return Expense::class;
    }

    protected function sortableColumns(): array
    {
        return [
            'expense_date',
            'expense_amount',
            'description',
            'invoice_no',
            'created_at',
        ];
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);

        $status = (string) $request->input('status', 'active');
        if ($status === 'deleted') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if ($val === null || $val === '') {
                continue;
            }
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($request->filled('from_date')) {
            $query->whereDate('expense_date', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('expense_date', '<=', $request->input('to_date'));
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('description', 'like', "%{$q}%")
                    ->orWhere('invoice_no', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $this->applyListOrdering($request, $query, 'expense_date', 'desc');

        return response()->json($query->paginate($perPage));
    }

    /** GET /expenses/summary */
    public function summary(Request $request)
    {
        $query = $this->baseQuery($request)->whereNull('deleted_at');
        $now = now();

        return response()->json([
            'today' => (float) (clone $query)->whereDate('expense_date', $now->toDateString())->sum('expense_amount'),
            'month' => (float) (clone $query)
                ->whereYear('expense_date', $now->year)
                ->whereMonth('expense_date', $now->month)
                ->sum('expense_amount'),
            'year' => (float) (clone $query)
                ->whereYear('expense_date', $now->year)
                ->sum('expense_amount'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|integer',
            'expense_group_id' => 'required|integer',
            'float_session_id' => 'nullable|integer',
            'description' => 'nullable|string|max:200',
            'expense_amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'balance_due' => 'nullable|string|max:45',
            'invoice_no' => 'nullable|string|max:45',
            'billable_status' => 'nullable|integer',
            'payment_method_id' => 'required|integer',
        ]);

        if (! empty($data['float_session_id'])) {
            $session = TillFloatSession::find($data['float_session_id']);
            if (! $session || strtolower((string) $session->status) !== 'open') {
                throw new InvalidArgumentException('Expenses can only be linked to an open till session.');
            }
            if ((int) $session->branch_id !== (int) $data['branch_id']) {
                throw new InvalidArgumentException('Expense branch must match the till session branch.');
            }
        }

        $data['recorded_by'] = $request->user()->id;
        $expense = Expense::create($data);

        $gate = $this->erp->gateForUser($request->user());
        app(ExpenseJournalService::class)->postIfEnabled($expense, $request->user(), $gate);

        return response()->json($expense, 201);
    }

    public function destroy(Request $request, string $id)
    {
        $expense = $this->findScopedModel($request, $id);
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);

        app(ReferenceJournalReversalService::class)->reverseIfEnabled(
            'expense',
            (int) $expense->id,
            $user,
            $gate,
        );

        if ($user && $this->auditable()) {
            $this->auditLogger()->logModel(
                $user,
                'delete',
                $expense,
                $expense->getAttributes(),
                null,
                $request,
            );
        }

        $expense->delete();

        return response()->json(null, 204);
    }
}
