<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseGroup;
use App\Models\MobileRouteExpense;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Accounting\ExpenseJournalService;
use App\Services\Accounting\ReferenceJournalReversalService;
use App\Services\Erp\ErpContext;
use App\Services\Organization\OrganizationReferenceDataService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Mirror mobile field expenses into the shared Accounting `expenses` table
 * so they appear on GET /api/v1/expenses like till / backoffice expenses.
 */
class MobileRouteExpenseLedgerSync
{
    public const EXPENSE_GROUP_NAME = 'Mobile route';

    public function __construct(
        protected OrganizationReferenceDataService $referenceData,
        protected ExpenseJournalService $journals,
        protected ReferenceJournalReversalService $reversals,
        protected ErpContext $erp,
    ) {}

    public function syncOnCreate(MobileRouteExpense $routeExpense, User $actor): Expense
    {
        $expense = $this->upsertLedgerExpense($routeExpense);
        $this->postJournal($expense, $actor);

        return $expense;
    }

    public function syncOnApprove(MobileRouteExpense $routeExpense, User $actor): Expense
    {
        $expense = $this->upsertLedgerExpense($routeExpense);
        if ($expense->trashed()) {
            $expense->restore();
            $expense->deleted_by = null;
            $expense->save();
        }
        $this->postJournal($expense, $actor);

        return $expense->fresh() ?? $expense;
    }

    public function removeOnReject(MobileRouteExpense $routeExpense, User $actor): void
    {
        $this->removeFromLedger($routeExpense, $actor);
    }

    /**
     * Soft-delete the Accounting expense row (and reverse its journal) for a mobile route expense.
     */
    public function removeFromLedger(MobileRouteExpense $routeExpense, User $actor): void
    {
        if (! $this->columnReady()) {
            return;
        }

        $expense = Expense::withTrashed()
            ->where('mobile_route_expense_id', $routeExpense->id)
            ->first();
        if (! $expense) {
            return;
        }

        $gate = $this->erp->gateForUser($actor);
        if (! $expense->trashed()) {
            $this->reversals->reverseIfEnabled('expense', (int) $expense->id, $actor, $gate);
            $expense->deleted_by = (int) $actor->id;
            $expense->save();
            $expense->delete();
        }
    }

    /**
     * When an Accounting expense is deleted, reject the linked mobile route expense
     * so it leaves Mobile Orders performed/pending and stops sales deductions.
     */
    public function rejectMobileRouteAfterLedgerDelete(Expense $expense, User $actor, ?string $reason = null): void
    {
        $routeId = (int) ($expense->mobile_route_expense_id ?? 0);
        if ($routeId <= 0 || ! Schema::hasTable('mobile_route_expenses')) {
            return;
        }

        $routeExpense = MobileRouteExpense::query()->find($routeId);
        if (! $routeExpense || $routeExpense->status === MobileRouteExpense::STATUS_REJECTED) {
            return;
        }

        $routeExpense->status = MobileRouteExpense::STATUS_REJECTED;
        $routeExpense->rejected_by = (int) $actor->id;
        $routeExpense->rejected_at = now();
        $routeExpense->reject_reason = mb_substr(
            $reason ?: 'Deleted from Accounting expenses',
            0,
            200,
        );
        $routeExpense->approved_by = null;
        $routeExpense->approved_at = null;
        $routeExpense->save();

        $rep = $routeExpense->user ?? User::query()->find($routeExpense->user_id);
        if ($rep) {
            $date = $routeExpense->expense_date
                ? \Carbon\Carbon::parse($routeExpense->expense_date)->startOfDay()
                : now()->startOfDay();
            app(MobileSalesService::class)->invalidateDashboardForUser($rep, $date);
        }
    }

    protected function upsertLedgerExpense(MobileRouteExpense $routeExpense): Expense
    {
        if (! $this->columnReady()) {
            throw ValidationException::withMessages([
                'expense' => ['Expenses ledger is missing mobile_route_expense_id. Run migrations.'],
            ]);
        }

        $orgId = (int) $routeExpense->organization_id;
        $branchId = $this->resolveBranchId($routeExpense);
        $groupId = $this->resolveExpenseGroupId($orgId);
        $paymentMethodId = $this->resolveCashPaymentMethodId($orgId);

        $payload = [
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'expense_group_id' => $groupId,
            'description' => mb_substr((string) $routeExpense->description, 0, 200),
            'expense_amount' => round((float) $routeExpense->expense_amount, 2),
            'expense_date' => $routeExpense->expense_date?->toDateString() ?? now()->toDateString(),
            'invoice_no' => 'MOBILE-'.$routeExpense->id,
            'payment_method_id' => $paymentMethodId,
            'recorded_by' => (int) $routeExpense->user_id,
            'mobile_route_expense_id' => (int) $routeExpense->id,
        ];

        $existing = Expense::withTrashed()
            ->where('mobile_route_expense_id', $routeExpense->id)
            ->first();

        if ($existing) {
            $existing->fill($payload);
            if ($existing->trashed() && $routeExpense->status !== MobileRouteExpense::STATUS_REJECTED) {
                $existing->restore();
                $existing->deleted_by = null;
            }
            $existing->save();

            return $existing->fresh() ?? $existing;
        }

        return Expense::create($payload);
    }

    protected function postJournal(Expense $expense, User $actor): void
    {
        $exists = \App\Models\JournalEntry::query()
            ->where('organization_id', (int) $actor->organization_id)
            ->where('reference_type', 'expense')
            ->where('reference_id', (int) $expense->id)
            ->where('status', 'posted')
            ->exists();
        if ($exists) {
            return;
        }

        $gate = $this->erp->gateForUser($actor);
        $this->journals->postIfEnabled($expense, $actor, $gate);
    }

    protected function resolveBranchId(MobileRouteExpense $routeExpense): int
    {
        $branchId = (int) ($routeExpense->branch_id ?: 0);
        if ($branchId > 0) {
            return $branchId;
        }

        $fromUser = (int) (User::query()->where('id', $routeExpense->user_id)->value('branch_id') ?: 0);
        if ($fromUser > 0) {
            return $fromUser;
        }

        $orgBranch = (int) (Branch::query()
            ->where('organization_id', (int) $routeExpense->organization_id)
            ->orderBy('id')
            ->value('id') ?: 0);
        if ($orgBranch > 0) {
            return $orgBranch;
        }

        throw ValidationException::withMessages([
            'branch_id' => ['Assign the mobile user to a branch before recording expenses.'],
        ]);
    }

    protected function resolveExpenseGroupId(int $organizationId): int
    {
        $this->referenceData->seedForOrganization($organizationId);

        $existing = ExpenseGroup::query()
            ->where('organization_id', $organizationId)
            ->where('group_name', self::EXPENSE_GROUP_NAME)
            ->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $fallback = ExpenseGroup::query()
            ->where('organization_id', $organizationId)
            ->whereIn('group_name', ['Fuel', 'Other', 'Transport'])
            ->orderByRaw("FIELD(group_name, 'Fuel', 'Other', 'Transport')")
            ->value('id');
        if ($fallback) {
            return (int) $fallback;
        }

        $created = ExpenseGroup::query()->create([
            'group_name' => self::EXPENSE_GROUP_NAME,
            'organization_id' => $organizationId,
        ]);

        return (int) $created->id;
    }

    protected function resolveCashPaymentMethodId(int $organizationId): int
    {
        $this->referenceData->ensureExpensePaymentMethods($organizationId);

        $exact = PaymentMethod::query()
            ->where('organization_id', $organizationId)
            ->whereRaw('UPPER(TRIM(method_code)) = ?', ['CASH'])
            ->where(function ($query) {
                $query->where('is_active', true)->orWhere('is_active', 1);
            })
            ->value('id');
        if ($exact) {
            return (int) $exact;
        }

        $any = PaymentMethod::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) {
                $query->where('is_active', true)->orWhere('is_active', 1);
            })
            ->orderBy('id')
            ->value('id');
        if ($any) {
            return (int) $any;
        }

        throw ValidationException::withMessages([
            'payment_method_id' => ['Ask an admin to enable at least one payment method (Cash) for expenses.'],
        ]);
    }

    protected function columnReady(): bool
    {
        return Schema::hasTable('expenses')
            && Schema::hasColumn('expenses', 'mobile_route_expense_id');
    }
}
