<?php

namespace App\Services\Notifications\Contracts;

use App\Models\ActionRequest;
use App\Models\User;

interface ActionRequestHandler
{
    public function type(): string;

    public function canApprove(User $user, ActionRequest $request): bool;

    public function approve(ActionRequest $request, User $user): void;

    public function reject(ActionRequest $request, User $user, ?string $reason): void;
}
