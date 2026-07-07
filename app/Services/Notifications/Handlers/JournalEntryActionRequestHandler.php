<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\User;
use App\Services\Accounting\JournalEntryApprovalService;
use App\Services\Notifications\Contracts\ActionRequestHandler;

class JournalEntryActionRequestHandler implements ActionRequestHandler
{
    public function __construct(
        protected JournalEntryApprovalService $journals,
    ) {}

    public function type(): string
    {
        return 'journal_entry';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        return $this->journals->canApprove($user);
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $this->journals->postFromActionRequest($request, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason, array $options = []): void
    {
        // Draft remains editable.
    }
}
