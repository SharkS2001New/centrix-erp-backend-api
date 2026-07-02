<?php

namespace App\Services\Notifications\Handlers;

use App\Models\ActionRequest;
use App\Models\SupplierReturnDocument;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Notifications\Contracts\ActionRequestHandler;
use App\Services\Purchasing\SupplierReturnDocumentService;

class SupplierReturnActionRequestHandler implements ActionRequestHandler
{
    public function __construct(
        protected SupplierReturnDocumentService $returns,
        protected UserPermissionService $permissions,
    ) {}

    public function type(): string
    {
        return 'supplier_return';
    }

    public function canApprove(User $user, ActionRequest $request): bool
    {
        if ((int) $user->organization_id !== (int) $request->organization_id) {
            return false;
        }

        return (bool) $user->is_admin
            || $this->permissions->hasPermission($user, 'purchasing.manage');
    }

    public function approve(ActionRequest $request, User $user): void
    {
        $doc = SupplierReturnDocument::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        $this->returns->approve($doc, $user);
    }

    public function reject(ActionRequest $request, User $user, ?string $reason): void
    {
        $doc = SupplierReturnDocument::query()
            ->where('organization_id', $request->organization_id)
            ->findOrFail((int) $request->reference_id);

        $this->returns->reject($doc, $user, $reason);
    }
}
