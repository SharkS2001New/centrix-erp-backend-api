<?php

namespace App\Services\Notifications;

use App\Models\ActionRequest;
use App\Models\ApprovalAction;
use App\Models\InAppNotification;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\TemporaryCart;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\Contracts\ActionRequestHandler;
use App\Services\Sales\SaleOrderPresentationService;
use App\Services\Notifications\Handlers\CustomerReturnActionRequestHandler;
use App\Services\Notifications\Handlers\CashAdvanceActionRequestHandler;
use App\Services\Notifications\Handlers\DamageWriteOffActionRequestHandler;
use App\Services\Notifications\Handlers\DiscountApprovalActionRequestHandler;
use App\Services\Notifications\Handlers\ExpenseActionRequestHandler;
use App\Services\Notifications\Handlers\JournalEntryActionRequestHandler;
use App\Services\Notifications\Handlers\LeaveActionRequestHandler;
use App\Services\Notifications\Handlers\LatenessWaiverActionRequestHandler;
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
        protected AuditLogger $audit,
        SupplierReturnActionRequestHandler $supplierReturnHandler,
        DiscountApprovalActionRequestHandler $discountHandler,
        OrderCancellationActionRequestHandler $orderCancelHandler,
        LeaveActionRequestHandler $leaveHandler,
        LatenessWaiverActionRequestHandler $latenessWaiverHandler,
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
            $latenessWaiverHandler,
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

            $this->audit->log(
                $requester,
                'approval_requested',
                'action_requests',
                (int) $request->id,
                null,
                [
                    'type' => $type,
                    'reference_type' => (string) $data['reference_type'],
                    'reference_id' => (int) $data['reference_id'],
                    'title' => (string) $data['title'],
                ],
            );

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

    public function reject(ActionRequest $request, User $approver, ?string $reason = null, array $options = []): ActionRequest
    {
        return $this->resolve(
            $request,
            $approver,
            'rejected',
            fn (ActionRequestHandler $handler) => $handler->reject($request, $approver, $reason, $options),
            $reason,
        );
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
            $this->notifyRequesterOfOutcome($request, $outcome, $actor, $comment);
        });
    }

    /** Withdraw pending approval requests when the referenced sale is cancelled. */
    public function cancelAllPendingForSale(Sale $sale, User $actor, ?string $comment = null): void
    {
        $this->cancelAllPendingForReference(
            $actor,
            'sale',
            (int) $sale->id,
            $comment,
        );
    }

    /** Withdraw pending cart discount requests when the cart is abandoned or cleared. */
    public function cancelAllPendingForCart(TemporaryCart $cart, User $actor, ?string $comment = null): void
    {
        $this->cancelAllPendingForReference(
            $actor,
            'temporary_cart',
            (int) $cart->id,
            $comment,
        );
    }

    protected function cancelAllPendingForReference(
        User $actor,
        string $referenceType,
        int $referenceId,
        ?string $comment = null,
    ): void {
        $requests = ActionRequest::query()
            ->where('organization_id', (int) $actor->organization_id)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('status', 'pending')
            ->get();

        foreach ($requests as $request) {
            $this->cancelPendingRequest($request, $actor, $comment);
        }
    }

    protected function cancelPendingRequest(
        ActionRequest $request,
        User $actor,
        ?string $comment = null,
    ): void {
        if (! $request->isPending()) {
            return;
        }

        DB::transaction(function () use ($request, $actor, $comment) {
            $request->update([
                'status' => 'cancelled',
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);

            ApprovalAction::query()->create([
                'organization_id' => $request->organization_id,
                'action_request_id' => $request->id,
                'user_id' => $actor->id,
                'action' => 'cancelled',
                'comment' => $comment,
            ]);

            $this->notifications->resolveForActionRequest($request);
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

    public function canRemind(User $actor, ActionRequest $request): bool
    {
        if ((int) $actor->organization_id !== (int) $request->organization_id) {
            return false;
        }

        if (! $request->isPending()) {
            return false;
        }

        return (int) $request->requested_by === (int) $actor->id || (bool) $actor->is_admin;
    }

    /** @return array<string, mixed>|null */
    public function presentForViewer(?ActionRequest $request, ?User $viewer): ?array
    {
        if ($request === null || $viewer === null) {
            return null;
        }

        return [
            'id' => (int) $request->id,
            'type' => $request->type,
            'status' => $request->status,
            'reason' => $request->reason,
            'payload' => $request->payload,
            'can_approve' => $this->canApprove($viewer, $request),
            'can_remind' => $this->canRemind($viewer, $request),
        ];
    }

    /** @return array<string, mixed>|null */
    public function presentPendingFor(
        User $viewer,
        string $type,
        string $referenceType,
        int $referenceId,
    ): ?array {
        $request = ActionRequest::query()
            ->where('organization_id', $viewer->organization_id)
            ->where('type', $type)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('status', 'pending')
            ->first();

        return $this->presentForViewer($request, $viewer);
    }

    public function sendReminder(ActionRequest $request, User $actor): ActionRequest
    {
        if ((int) $actor->organization_id !== (int) $request->organization_id) {
            throw ValidationException::withMessages(['action_request' => 'Action request not found.']);
        }

        if (! $request->isPending()) {
            throw ValidationException::withMessages(['status' => 'This request has already been resolved.']);
        }

        if (! $this->canRemind($actor, $request)) {
            throw ValidationException::withMessages(['action_request' => 'You cannot send a reminder for this request.']);
        }

        $requester = User::query()->findOrFail((int) $request->requested_by);
        $recipients = $this->resolveApprovers($requester, $request);
        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages(['approvers' => 'No approvers are available to notify.']);
        }

        return DB::transaction(function () use ($request, $actor, $requester, $recipients) {
            ApprovalAction::query()->create([
                'organization_id' => $request->organization_id,
                'action_request_id' => $request->id,
                'user_id' => $actor->id,
                'action' => 'reminded',
                'comment' => null,
            ]);

            $payload = $request->payload ?? [];
            $detail = trim((string) ($payload['message'] ?? ''));
            if ($detail === '') {
                $detail = (string) $request->title;
            }
            $actorName = $actor->full_name ?: $actor->username;
            $message = "{$actorName} sent an approval reminder — {$detail}";

            $this->audit->log(
                $actor,
                'approval_reminded',
                'action_requests',
                (int) $request->id,
                null,
                [
                    'type' => $request->type,
                    'recipient_count' => $recipients->count(),
                ],
            );

            if ($this->inAppApprovalRequestEnabled($requester)) {
                foreach ($recipients as $recipient) {
                    $this->notifications->createForUser($recipient, [
                        'organization_id' => $request->organization_id,
                        'action_request_id' => (int) $request->id,
                        'type' => 'approval',
                        'severity' => 'warning',
                        'title' => 'Approval reminder',
                        'message' => $message,
                        'action_url' => $payload['action_url'] ?? null,
                        'created_by' => $actor->id,
                    ]);
                }
            }

            return $request->fresh(['requester']);
        });
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

        if ($request->type === 'discount') {
            return $this->permissions
                ->usersWhoCanApproveDiscountRequests((int) $requester->organization_id)
                ->filter(fn (User $user) => (int) $user->id !== (int) $requester->id)
                ->values();
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

            $this->audit->log(
                $approver,
                'approval_'.$outcome,
                'action_requests',
                (int) $request->id,
                ['status' => 'pending', 'type' => $request->type],
                [
                    'status' => $outcome,
                    'type' => $request->type,
                    'reference_type' => $request->reference_type,
                    'reference_id' => (int) $request->reference_id,
                    'comment' => $comment,
                ],
            );

            $this->notifications->resolveForActionRequest($request);
            $this->notifyRequesterOfOutcome($request->fresh(), $outcome, $approver, $comment);

            return $request->fresh(['requester', 'approvalActions']);
        });
    }

    protected function notifyRequesterOfOutcome(ActionRequest $request, string $outcome, User $actor, ?string $comment = null): void
    {
        $requester = User::query()->find((int) $request->requested_by);
        if ($requester === null || (int) $requester->id === (int) $actor->id) {
            return;
        }

        if (! $this->shouldNotifyRequesterOfOutcome($request, $requester)) {
            return;
        }

        $approved = $outcome === 'approved';
        $message = $approved
            ? "Your request \"{$request->title}\" was approved by {$actor->full_name}."
            : "Your request \"{$request->title}\" was rejected by {$actor->full_name}.";

        if ($request->type === 'discount') {
            $orderNum = (int) (($request->payload ?? [])['order_num'] ?? 0);
            $orderLabel = $orderNum > 0 ? "order #{$orderNum}" : 'your order';

            if ($approved) {
                $message = "Your discount on {$orderLabel} was approved by {$actor->full_name}.";
            } else {
                $message = "Your discount on {$orderLabel} was rejected. Edit the order and resubmit for approval.";
                if ($comment) {
                    $message .= " Reason: {$comment}";
                }
                $sale = $request->reference_type === 'sale'
                    ? Sale::query()->find((int) $request->reference_id)
                    : null;
                $approvalMeta = is_array($sale?->fulfillment_meta['discount_approval'] ?? null)
                    ? $sale->fulfillment_meta['discount_approval']
                    : [];
                $guidance = app(SaleOrderPresentationService::class)->discountRejectionGuidanceMessage($approvalMeta);
                if ($guidance !== '') {
                    $message .= " {$guidance}.";
                }
            }
        } elseif ($request->type === 'lpo_approval') {
            $payload = $request->payload ?? [];
            $poNumber = trim((string) ($payload['po_number'] ?? ''));
            $supplier = trim((string) ($payload['supplier_name'] ?? ''));
            $poLabel = $poNumber !== '' ? $poNumber : 'your purchase order';
            $supplierLabel = $supplier !== '' ? " for {$supplier}" : '';

            if ($approved) {
                $message = "{$poLabel}{$supplierLabel} was approved by {$actor->full_name}. You can now send it to the supplier.";
            } else {
                $message = "{$poLabel}{$supplierLabel} was rejected. Revise the order and submit again for approval.";
                if ($comment) {
                    $message .= " Reason: {$comment}";
                }
            }
        } elseif (! $approved && $comment) {
            $message .= " Reason: {$comment}";
        }

        $title = match (true) {
            $request->type === 'discount' && $approved => 'Discount approved',
            $request->type === 'discount' => 'Discount rejected',
            $request->type === 'lpo_approval' && $approved => 'LPO approved',
            $request->type === 'lpo_approval' => 'LPO rejected',
            $approved => 'Request approved',
            default => 'Request rejected',
        };

        $this->notifications->createForUser($requester, [
            'organization_id' => $request->organization_id,
            'action_request_id' => (int) $request->id,
            'type' => 'approval_outcome',
            'severity' => $approved ? 'success' : 'danger',
            'title' => $title,
            'message' => $message,
            'action_url' => $approved
                ? ($request->payload['action_url'] ?? null)
                : ($request->type === 'discount' && $request->reference_type === 'sale'
                    ? app(\App\Services\Sales\DiscountApprovalService::class)->saleEditableActionUrl(
                        Sale::query()->findOrFail((int) $request->reference_id),
                    )
                    : ($request->payload['action_url'] ?? null)),
            'created_by' => $actor->id,
        ]);
    }

    protected function shouldNotifyRequesterOfOutcome(ActionRequest $request, User $requester): bool
    {
        if (in_array($request->type, ['discount', 'lpo_approval'], true)) {
            return true;
        }

        return $this->inAppApprovalOutcomeEnabled($requester);
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
