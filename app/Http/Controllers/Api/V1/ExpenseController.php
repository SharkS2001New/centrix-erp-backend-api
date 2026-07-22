<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Expense;
use App\Services\Accounting\ExpenseApprovalService;
use App\Services\Accounting\ExpenseJournalService;
use App\Services\Accounting\ReferenceJournalReversalService;
use App\Services\Erp\ErpContext;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ExpenseController extends BaseResourceController
{
    public const DEFAULT_RANGE_DAYS = 30;

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

        $q = trim((string) $request->input('q', ''));
        $hasFrom = $request->filled('from_date');
        $hasTo = $request->filled('to_date');
        if (! $hasFrom && ! $hasTo && $q === '') {
            $to = now()->toDateString();
            $from = Carbon::parse($to)->subDays(self::DEFAULT_RANGE_DAYS - 1)->toDateString();
            $query->whereDate('expense_date', '>=', $from)
                ->whereDate('expense_date', '<=', $to);
        } else {
            if ($hasFrom) {
                $query->whereDate('expense_date', '>=', $request->input('from_date'));
            }
            if ($hasTo) {
                $query->whereDate('expense_date', '<=', $request->input('to_date'));
            }
        }

        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->where('description', 'like', "%{$q}%")
                    ->orWhere('invoice_no', 'like', "%{$q}%");
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
            'dispatch_trip_id' => 'nullable|integer|exists:dispatch_trips,id',
            'description' => 'nullable|string|max:200',
            'expense_amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'balance_due' => 'nullable|string|max:45',
            'invoice_no' => 'nullable|string|max:45',
            'billable_status' => 'nullable|integer',
            'payment_method_id' => 'required|integer',
        ]);

        $user = $request->user();
        abort_unless($user, 401);

        // Same org/branch checks as the approval path — never allow posting to another tenant's branch.
        app(ExpenseApprovalService::class)->validateCreateDataForUser($user, $data);

        if (! app(ExpenseApprovalService::class)->canApprove($user)) {
            $actionRequest = app(ExpenseApprovalService::class)->requestCreate($user, $data);

            return response()->json([
                'message' => 'Expense submitted for admin approval.',
                'pending_approval' => true,
                'action_request_id' => (int) $actionRequest->id,
            ], 202);
        }

        $data['recorded_by'] = $user->id;
        $data['organization_id'] = (int) (
            \App\Support\OrganizationIdResolver::requireForBranch((int) $data['branch_id'])
        );
        $expense = Expense::create($data);

        $gate = $this->erp->gateForUser($user);
        app(ExpenseJournalService::class)->postIfEnabled($expense, $user, $gate);

        return response()->json($expense, 201);
    }

    public function destroy(Request $request, string $id)
    {
        $expense = $this->findScopedModel($request, $id);
        $user = $request->user();
        abort_unless($user, 401);

        if (! app(ExpenseApprovalService::class)->canApprove($user)) {
            $actionRequest = app(ExpenseApprovalService::class)->requestDelete($user, $expense);

            return response()->json([
                'message' => 'Expense deletion submitted for admin approval.',
                'pending_approval' => true,
                'action_request_id' => (int) $actionRequest->id,
            ], 202);
        }

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
