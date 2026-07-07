<?php

namespace App\Services\Accounting;

use App\Models\ActionRequest;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\TillFloatSession;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ExpenseApprovalService
{
    public function __construct(
        protected UserPermissionService $permissions,
        protected UserAccessService $access,
        protected ErpContext $erp,
    ) {}

    public function canApprove(User $user): bool
    {
        return $this->permissions->canApproveExpenses($user);
    }

    /** @param  array<string, mixed>  $data */
    public function requestCreate(User $requester, array $data): ActionRequest
    {
        $this->validateCreateData($requester, $data);
        $requesterName = $requester->full_name ?: $requester->username;
        $amount = number_format((float) $data['expense_amount'], 2);
        $actionUrl = NotificationActionUrlBuilder::for('expense', 0);

        return app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'expense_action',
            'module' => 'accounting',
            'reference_type' => 'expense',
            'reference_id' => 0,
            'approver_permission' => 'accounting.manage',
            'title' => 'Expense approval required',
            'message' => "{$requesterName} requested KES {$amount} expense approval.",
            'reason' => $data['description'] ?? null,
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'allow_duplicate_reference' => true,
            'payload' => [
                'action' => 'create',
                'data' => $data,
                'action_url' => $actionUrl,
            ],
        ]);
    }

    public function requestDelete(User $requester, Expense $expense): ActionRequest
    {
        $requesterName = $requester->full_name ?: $requester->username;
        $amount = number_format((float) $expense->expense_amount, 2);
        $actionUrl = NotificationActionUrlBuilder::for('expense', (int) $expense->id);

        return app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'expense_action',
            'module' => 'accounting',
            'reference_type' => 'expense',
            'reference_id' => (int) $expense->id,
            'approver_permission' => 'accounting.manage',
            'title' => 'Expense deletion approval required',
            'message' => "{$requesterName} requested deletion of KES {$amount} expense.",
            'reason' => $expense->description,
            'severity' => 'danger',
            'action_url' => $actionUrl,
            'payload' => [
                'action' => 'delete',
                'expense_id' => (int) $expense->id,
                'action_url' => $actionUrl,
            ],
        ]);
    }

    public function apply(ActionRequest $request, User $approver): ?Expense
    {
        $payload = $request->payload ?? [];
        $action = (string) ($payload['action'] ?? '');

        return match ($action) {
            'create' => $this->createApprovedExpense($payload['data'] ?? [], $request, $approver),
            'delete' => $this->deleteApprovedExpense((int) ($payload['expense_id'] ?? $request->reference_id), $request, $approver),
            default => throw ValidationException::withMessages(['action' => 'Unsupported expense approval action.']),
        };
    }

    /** @param  array<string, mixed>  $data */
    protected function validateCreateData(User $user, array $data): void
    {
        $branchId = (int) ($data['branch_id'] ?? 0);
        $this->access->assertBranchAccess($user, $branchId);

        $branch = Branch::query()->findOrFail($branchId);
        if ((int) $branch->organization_id !== (int) $user->organization_id) {
            throw ValidationException::withMessages(['branch_id' => 'Expense branch is not in your organization.']);
        }

        if (! empty($data['float_session_id'])) {
            $session = TillFloatSession::find($data['float_session_id']);
            if (! $session || strtolower((string) $session->status) !== 'open') {
                throw new InvalidArgumentException('Expenses can only be linked to an open till session.');
            }
            if ((int) $session->branch_id !== $branchId) {
                throw new InvalidArgumentException('Expense branch must match the till session branch.');
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function createApprovedExpense(array $data, ActionRequest $request, User $approver): Expense
    {
        $requester = User::query()->findOrFail((int) $request->requested_by);
        $this->validateCreateData($requester, $data);

        return DB::transaction(function () use ($data, $requester, $approver) {
            $data['recorded_by'] = $requester->id;
            $expense = Expense::create($data);
            $gate = $this->erp->gateForUser($approver);
            app(ExpenseJournalService::class)->postIfEnabled($expense, $approver, $gate);

            return $expense->fresh();
        });
    }

    protected function deleteApprovedExpense(int $expenseId, ActionRequest $request, User $approver): ?Expense
    {
        $expense = Expense::query()->findOrFail($expenseId);

        return DB::transaction(function () use ($expense, $approver) {
            $gate = $this->erp->gateForUser($approver);
            app(ReferenceJournalReversalService::class)->reverseIfEnabled(
                'expense',
                (int) $expense->id,
                $approver,
                $gate,
            );
            $expense->delete();

            return null;
        });
    }
}
