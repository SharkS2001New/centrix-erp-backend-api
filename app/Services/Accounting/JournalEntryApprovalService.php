<?php

namespace App\Services\Accounting;

use App\Models\ActionRequest;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Notifications\ActionRequestService;
use App\Services\Notifications\NotificationActionUrlBuilder;
use Illuminate\Validation\ValidationException;

class JournalEntryApprovalService
{
    public function __construct(
        protected JournalPostingService $posting,
        protected UserPermissionService $permissions,
        protected ErpContext $erp,
    ) {}

    public function approvalEnabled(CapabilityGate $gate): bool
    {
        $settings = $gate->moduleSettings('accounting');

        return ! empty($settings['journal_entry_approval_enabled']);
    }

    public function canDirectPost(User $user): bool
    {
        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'accounting.manage')
            || $this->permissions->hasPermission($user, 'accounting.journal_entries.approve');
    }

    public function requestPost(User $user, JournalEntry $entry): ActionRequest
    {
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Only draft journal entries can be submitted for posting approval.',
            ]);
        }

        if ($this->canDirectPost($user)) {
            throw ValidationException::withMessages([
                'authorization' => 'You can post this journal entry directly.',
            ]);
        }

        $requesterName = $user->full_name ?: $user->username;
        $actionUrl = NotificationActionUrlBuilder::for('journal_entry', (int) $entry->id);

        return app(ActionRequestService::class)->requestApproval($user, [
            'type' => 'journal_entry',
            'module' => 'accounting',
            'reference_type' => 'journal_entry',
            'reference_id' => (int) $entry->id,
            'approver_permission' => 'accounting.journal_entries.approve',
            'title' => 'Journal entry posting approval',
            'message' => "{$requesterName} requested posting of journal {$entry->entry_number}.",
            'reason' => $entry->description,
            'severity' => 'warning',
            'action_url' => $actionUrl,
            'payload' => [
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date,
                'action_url' => $actionUrl,
            ],
        ]);
    }

    public function postEntry(JournalEntry $entry, User $approver): JournalEntry
    {
        if ((int) $entry->organization_id !== (int) $approver->organization_id) {
            abort(404);
        }

        return $this->posting->postDraft($entry->load('lines'));
    }

    public function postFromActionRequest(ActionRequest $request, User $approver): JournalEntry
    {
        $entry = JournalEntry::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        return $this->postEntry($entry, $approver);
    }
}
