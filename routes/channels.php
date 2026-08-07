<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', function (User $user, int $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('organization.{organizationId}', function (User $user, int $organizationId) {
    return (int) ($user->organization_id ?? 0) === (int) $organizationId;
});
