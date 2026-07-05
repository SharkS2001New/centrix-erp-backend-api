<?php

namespace App\Services\Notifications;

use App\Models\ActionRequest;
use App\Models\ApprovalAction;
use App\Models\InAppNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\Contracts\ActionRequestHandler;
use App\Services\Notifications\Handlers\CustomerReturnActionRequestHandler;
use App\Services\Notifications\Handlers\CashAdvanceActionRequestHandler;
use App\Services\Notifications\Handlers\DamageWriteOffActionRequestHandler;
use App\Services\Notifications\Handlers\DiscountApprovalActionRequestHandler;
use App\Services\Notifications\Handlers\ExpenseActionRequestHandler;
use App\Services\Notifications\Handlers\JournalEntryActionRequestHandler;
use App\Services\Notifications\Handlers\LeaveActionRequestHandler;
use App\Services\Notifications\Handlers\LpoApprovalActionRequestHandler;
use App\Services\Notifications\Handlers\OrderCancellationActionRequestHandler;
use App\Services\Notifications\Handlers\PayrollRunActionRequestHandler;
use App\Services\Notifications\Handlers\StockAdjustmentActionRequestHandler;
use App\Services\Notifications\Handlers\StockTakeCompletionActionRequestHandler;
use App\Services\Notifications\Handlers\StockTransferActionRequestHandler;
use App\Services\Notifications\Handlers\SupplierReturnActionRequestHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActionRequestService
{
    /** @var Collection<string, ActionRequestHandler> */
    protected Collection $handlers;

    public function __construct(
        protected InAppNotificationService $notifications,
        protected UserPermissionService $permissions,
        SupplierReturnActionRequestHandler $supplierReturnHandler,
        DiscountApprovalActionRequestHandler $discountHandler,
        OrderCancellationActionRequestHandler $orderCancelHandler,
        LeaveActionRequestHandler $leaveHandler,
        JournalEntryActionRequestHandler $journalHandler,
        StockAdjustmentActionRequestHandler $stockAdjustmentHandler,
        StockTransferActionRequestHandler $stockTransferHandler,
        CustomerReturnActionRequestHandler $customerReturnHandler,
        LpoApprovalActionRequestHandler $lpoApprovalHandler,
        PayrollRunActionRequestHandler $payrollRunHandler,
        CashAdvanceActionRequestHandler $cashAdvanceHandler,
        ExpenseActionRequestHandler $expenseActionHandler,
        StockTakeCompletionActionRequestHandler $stockTakeCompletionHandler,
        DamageWriteOffActionRequestHandler $damageWriteOffHandler,
    ) {
        $this->handlers = collect([
            $supplierReturnHandler,
            $discountHandler,
            $orderCancelHandler,
            $leaveHandler,
            $journalHandler,
            $stockAdjustmentHandler,
            $stockTransferHandler,
            $customerReturnHandler,
            $lpoApprovalHandler,
            $payrollRunHandler,
            $cashAdvanceHandler,
            $expenseActionHandler,
            $stockTakeCompletionHandler,
            $damageWriteOffHandler,
        ])->keyBy(fn (ActionRequestHandler $handler) => $handler->type());
    }

    /** @param  array<string, mixed>  $data */
    public function requestApproval(User $requester, array $data): ActionRequest
    {
        $type = (string) ($data['type'] ?? '');
        $handler = $this->handlers->get($type);
        if ($handler === null) {
            throw ValidationException::withMessages(['type' => 'Unknown action request type.']);
        }

        $existing = null;
        if (empty($data['allow_duplicate_reference'])) {
            $existing = ActionRequest::query()
                ->where('organization_id', $requester->organization_id)
                ->where('type', $type)
                ->where('reference_type', (string) $data['reference_type'])
                ->where('reference_id', (int) $data['reference_id'])
                ->where('status', 'pending')
                ->first();
        }

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($requester, $data, $type) {
            $request = ActionRequest::query()->create([
                'organization_id' => $requester->organization_id,
                'type' => $type,
                'module' => $data['module'] ?? null,
                'reference_type' => (string) $data['reference_type'],
                'reference_id' => (int) $data['reference_id'],
                'requested_by' => $requester->id,
                'assigned_to' => $data['assigned_to'] ?? null,
                'approver_permission' => $data['approver_permission'] ?? null,
                'status' => 'pending',
                'title' => (string) $data['title'],
                'reason' => $data['reason'] ?? null,
                'payload' => $data['payload'] ?? null,
            ]);

            ApprovalAction::query()->create([
                'organization_id' => $requester->organization_id,
                'action_request_id' => $request->id,
                'user_id' => $requester->id,
                'action' => 'requested',
                'comment' => $data['reason'] ?? null,
            ]);

            $message = (string) ($data['message'] ?? $data['title']);
            $severity = (string) ($data['severity'] ?? 'warning');
            $actionUrl = $data['action_url'] ?? null;

            $recipients = $this->resolveApprovers($requester, $request);
            if ($this->inAppApprovalRequestEnabled($requester)) {
                foreach ($recipients as $recipient) {
                    $this->notifications->createForUser($recipient, [
                        'organization_id' => $requester->organization_id,
                        'action_request_id' => $request->id,
                        'type' => 'approval',
                        'severity' => $severity,
                        'title' => (string) $data['title'],
                        'message' => $message,
                        'action_url' => $actionUrl,
                        'created_by' => $requester->id,
                    ]);
                }
            }

            return $request->fresh(['requester']);
        });
    }

    public function approve(ActionRequest $request, User $approver): ActionRequest
    {
        return $this->resolve($request, $approver, 'approved', fn (ActionRequestHandler $handler) => $handler->approve($request, $approver));
    }

    public function reject(ActionRequest $request, User $approver, ?string $reason = null): ActionRequest
    {
        return $this->resolve($request, $approver, 'rejected', fn (ActionRequestHandler $handler) => $handler->reject($request, $approver, $reason), $reason);
    }

    public function markResolvedFromDomain(
        string $type,
        string $referenceType,
        int $referenceId,
        string $outcome,
        User $actor,
        ?string $comment = null,
    ): void {
        $request = ActionRequest::query()
            ->where('organization_id', $actor->organization_id)
            ->where('type', $type)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('status', 'pending')
            ->first();

        if ($request === null) {
            return;
        }

        DB::transaction(function () use ($request, $outcome, $actor, $comment) {
            $request->update([
                'status' => $outcome,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);

            ApprovalAction::query()->create([
                'organization_id' => $request->organization_id,
                'action_request_id' => $request->id,
                'user_id' => $actor->id,
                'action' => $outcome,
                'comment' => $comment,
            ]);

            $this->notifications->resolveForActionRequest($request);
            $this->notifyRequesterOfOutcome($request, $outcome, $actor);
        });
    }

    public function handlerFor(ActionRequest $request): ?ActionRequestHandler
    {
        return $this->handlers->get($request->type);
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        $handler = $this->handlerFor($request);
        if ($handler === null || ! $request->isPending()) {
            return false;
        }

        return $handler->canApprove($user, $request);
    }

    /** @return Collection<int, User> */
    protected function resolveApprovers(User $requester, ActionRequest $request): Collection
    {
        if ($request->assigned_to) {
            $assignee = User::query()
                ->where('organization_id', $requester->organization_id)
                ->where('id', (int) $request->assigned_to)
                ->where('is_active', true)
                ->first();

            return $assignee && (int) $assignee->id !== (int) $requester->id
                ? collect([$assignee])
                : collect();
        }

        $permission = $request->approver_permission;
        if (! is_string($permission) || $permission === '') {
            return collect();
        }

        return $this->permissions
            ->usersWithPermission((int) $requester->organization_id, $permission)
            ->filter(fn (User $user) => (int) $user->id !== (int) $requester->id)
            ->values();
    }

    protected function resolve(
        ActionRequest $request,
        User $approver,
        string $outcome,
        callable $domainAction,
        ?string $comment = null,
    ): ActionRequest {
        if ((int) $approver->organization_id !== (int) $request->organization_id) {
            throw ValidationException::withMessages(['action_request' => 'Action request not found.']);
        }

        if (! $request->isPending()) {
            throw ValidationException::withMessages(['status' => 'This request has already been resolved.']);
        }

        $handler = $this->handlerFor($request);
        if ($handler === null || ! $handler->canApprove($approver, $request)) {
            throw ValidationException::withMessages(['action_request' => 'You are not allowed to resolve this request.']);
        }

        return DB::transaction(function () use ($request, $approver, $outcome, $domainAction, $comment) {
            $domainAction($this->handlerFor($request));

            $request->update([
                'status' => $outcome,
                'resolved_by' => $approver->id,
                'resolved_at' => now(),
            ]);

            ApprovalAction::query()->create([
                'organization_id' => $request->organization_id,
                'action_request_id' => $request->id,
                'user_id' => $approver->id,
                'action' => $outcome,
                'comment' => $comment,
            ]);

            $this->notifications->resolveForActionRequest($request);
            $this->notifyRequesterOfOutcome($request->fresh(), $outcome, $approver);

            return $request->fresh(['requester', 'approvalActions']);
        });
    }

    protected function notifyRequesterOfOutcome(ActionRequest $request, string $outcome, User $actor): void
    {
        $requester = User::query()->find((int) $request->requested_by);
        if ($requester === null || (int) $requester->id === (int) $actor->id) {
            return;
        }

        $approved = $outcome === 'approved';
        if (! $this->inAppApprovalOutcomeEnabled($requester)) {
            return;
        }

        $this->notifications->createForUser($requester, [
            'organization_id' => $request->organization_id,
            'type' => 'info',
            'severity' => $approved ? 'success' : 'danger',
            'title' => $approved ? 'Request approved' : 'Request rejected',
            'message' => $approved
                ? "Your request \"{$request->title}\" was approved by {$actor->full_name}."
                : "Your request \"{$request->title}\" was rejected by {$actor->full_name}.",
            'action_url' => $request->payload['action_url'] ?? null,
            'created_by' => $actor->id,
        ]);
    }

    protected function inAppApprovalRequestEnabled(User $user): bool
    {
        $organization = Organization::query()->find((int) $user->organization_id);
        if (! $organization) {
            return false;
        }

        $settings = NotificationSettingsResolver::forOrganization($organization);

        return NotificationSettingsResolver::inAppEventEnabled(
            $settings,
            InAppNotificationEvents::APPROVAL_REQUEST,
        );
    }

    protected function inAppApprovalOutcomeEnabled(User $user): bool
    {
        $organization = Organization::query()->find((int) $user->organization_id);
        if (! $organization) {
            return false;
        }

        $settings = NotificationSettingsResolver::forOrganization($organization);

        return NotificationSettingsResolver::inAppEventEnabled(
            $settings,
            InAppNotificationEvents::APPROVAL_OUTCOME,
        );
    }
}
