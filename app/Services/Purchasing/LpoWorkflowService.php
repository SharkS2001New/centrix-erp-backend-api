<?php

namespace App\Services\Purchasing;

use App\Models\LpoMst;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LpoModuleService;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\OrganizationMailSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LpoWorkflowService
{
    public const STATUS_AWAITING_CHECK = 0;
    public const STATUS_AWAITING_APPROVAL = 1;
    public const STATUS_AWAITING_SEND = 2;
    public const STATUS_AWAITING_RECEIVE = 3;

    public function __construct(
        protected LpoModuleService $lpoModule,
    ) {}

    /** @return list<string> */
    public function workflowActions(LpoMst $lpo, ?int $organizationId = null, ?Supplier $supplier = null): array
    {
        $settings = ProcurementSettingsResolver::forOrganizationId($organizationId);
        $status = (int) ($lpo->lpo_status_code ?? 0);
        $actions = [];

        if ($status === self::STATUS_AWAITING_CHECK) {
            $actions[] = 'submit_for_approval';
        }

        if ($status === self::STATUS_AWAITING_APPROVAL && $settings['require_lpo_approval']) {
            $actions[] = 'submit_for_approval';
            $actions[] = 'approve';
        }

        if ($status === self::STATUS_AWAITING_SEND) {
            $supplier ??= Supplier::find($lpo->supplier_id);
            if ($supplier?->email) {
                $actions[] = 'send_email';
            }
            $actions[] = 'send_whatsapp';
            $actions[] = 'mark_sent';
        }

        return $actions;
    }

    public function applyAction(LpoMst $lpo, string $action, User $user, Organization $organization): LpoMst
    {
        $settings = ProcurementSettingsResolver::forOrganization($organization);
        $status = (int) ($lpo->lpo_status_code ?? 0);

        return DB::transaction(function () use ($lpo, $action, $user, $organization, $settings, $status) {
            $lpo = LpoMst::query()->where('lpo_no', $lpo->lpo_no)->lockForUpdate()->firstOrFail();

            if (in_array($action, ['approve', 'mark_checked'], true)) {
                app(LpoApprovalService::class)->assertCanApprove($user);
            }

            if ($action === 'submit_for_approval') {
                $this->assertCanSubmitForApproval($user);
            }

            match ($action) {
                'mark_checked' => $this->markChecked($lpo, $settings, $user),
                'submit_for_approval' => $this->submitForApproval($lpo, $settings, $user),
                'approve' => $this->approve($lpo, $settings, $status),
                'mark_sent' => $this->markSent($lpo, $settings, $organization, $user, $status),
                default => throw ValidationException::withMessages([
                    'action' => ['Unsupported workflow action.'],
                ]),
            };

            $fresh = $lpo->fresh();
            if (
                in_array($action, ['mark_checked', 'submit_for_approval'], true)
                && (int) $fresh->lpo_status_code === self::STATUS_AWAITING_APPROVAL
                && $settings['require_lpo_approval']
            ) {
                app(LpoApprovalService::class)->requestApproval($user, $fresh);
            }
            if ($action === 'approve') {
                app(ActionRequestService::class)->markResolvedFromDomain(
                    'lpo_approval',
                    'lpo_mst',
                    (int) $fresh->lpo_no,
                    'approved',
                    $user,
                );
            }

            return $fresh;
        });
    }

    protected function markChecked(LpoMst $lpo, array $settings, User $user): void
    {
        if ((int) $lpo->lpo_status_code !== self::STATUS_AWAITING_CHECK) {
            throw ValidationException::withMessages([
                'action' => ['This LPO is not awaiting check.'],
            ]);
        }

        $lpo->update([
            'lpo_status_code' => $settings['require_lpo_approval']
                ? self::STATUS_AWAITING_APPROVAL
                : self::STATUS_AWAITING_SEND,
            'created_by' => $lpo->created_by ?? $user->id,
        ]);
    }

    protected function submitForApproval(LpoMst $lpo, array $settings, User $user): void
    {
        $status = (int) $lpo->lpo_status_code;

        if ($status === self::STATUS_AWAITING_CHECK) {
            $this->markChecked($lpo, $settings, $user);

            return;
        }

        if ($status === self::STATUS_AWAITING_APPROVAL) {
            if (! $settings['require_lpo_approval']) {
                throw ValidationException::withMessages([
                    'action' => ['LPO approval is not required for this organization.'],
                ]);
            }

            return;
        }

        throw ValidationException::withMessages([
            'action' => ['This LPO cannot be submitted for approval in its current status.'],
        ]);
    }

    protected function assertCanSubmitForApproval(User $user): void
    {
        if ($user->is_admin) {
            return;
        }

        $permissions = app(\App\Services\Auth\UserPermissionService::class);
        foreach (['purchasing.lpo.create', 'purchasing.lpo.edit', 'purchasing.manage'] as $code) {
            if ($permissions->hasPermission($user, $code)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'action' => ['You do not have permission to submit LPOs for approval.'],
        ]);
    }

    protected function approve(LpoMst $lpo, array $settings, int $status): void
    {
        if (! $settings['require_lpo_approval']) {
            throw ValidationException::withMessages([
                'action' => ['LPO approval is not required for this organization.'],
            ]);
        }

        if ($status !== self::STATUS_AWAITING_APPROVAL) {
            throw ValidationException::withMessages([
                'action' => ['This LPO is not awaiting approval.'],
            ]);
        }

        $lpo->update(['lpo_status_code' => self::STATUS_AWAITING_SEND]);
    }

    public function approveFromActionRequest(LpoMst $lpo, User $user, Organization $organization): LpoMst
    {
        $settings = ProcurementSettingsResolver::forOrganization($organization);
        $status = (int) ($lpo->lpo_status_code ?? 0);
        $this->approve($lpo, $settings, $status);

        return $lpo->fresh();
    }

    protected function markSent(
        LpoMst $lpo,
        array $settings,
        Organization $organization,
        User $user,
        int $status,
    ): void {
        if ($status !== self::STATUS_AWAITING_SEND) {
            throw ValidationException::withMessages([
                'action' => ['This LPO is not ready to be marked as sent.'],
            ]);
        }

        $lpo->update([
            'lpo_status_code' => self::STATUS_AWAITING_RECEIVE,
            'sent_at' => now(),
            'sent_by' => $user->id,
            'email_sent_flag' => $settings['auto_email_supplier_on_lpo'] ? 1 : (int) ($lpo->email_sent_flag ?? 0),
        ]);

        if ($settings['auto_email_supplier_on_lpo']) {
            $this->maybeEmailSupplier($lpo, $organization);
        }
    }

    protected function maybeEmailSupplier(LpoMst $lpo, Organization $organization): void
    {
        $supplier = Supplier::find($lpo->supplier_id);
        $email = trim((string) ($supplier?->email ?? ''));
        if ($email === '') {
            return;
        }

        $poNumber = $this->lpoModule->formatPoNumber((int) $lpo->lpo_seq);
        $body = implode("\n", array_filter([
            "Purchase order {$poNumber}",
            $supplier?->supplier_name ? "Supplier: {$supplier->supplier_name}" : null,
            $lpo->due_date ? "Valid until: {$lpo->due_date}" : null,
            'Total: KES '.number_format((float) ($lpo->net_amount ?? 0), 2),
            '',
            'Please refer to the attached LPO document shared separately.',
        ]));

        app(OrganizationMailSender::class)->sendRaw(
            $organization,
            $email,
            "LPO {$poNumber}",
            $body,
            requireNotificationsEnabled: true,
        );
    }
}
