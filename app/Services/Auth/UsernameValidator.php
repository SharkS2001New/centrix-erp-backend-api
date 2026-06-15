<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserMembership;
use Illuminate\Validation\ValidationException;

class UsernameValidator
{
    public function assertUniqueInOrganization(
        int $organizationId,
        string $username,
        ?int $ignoreUserId = null,
        ?int $ignoreMembershipId = null,
    ): void {
        $username = trim($username);
        if ($username === '') {
            return;
        }

        $userQuery = User::query()
            ->where('organization_id', $organizationId)
            ->where('username', $username)
            ->whereNull('deleted_at');

        if ($ignoreUserId) {
            $userQuery->where('id', '!=', $ignoreUserId);
        }

        if ($userQuery->exists()) {
            throw ValidationException::withMessages([
                'username' => ['This username is already taken in your organization.'],
            ]);
        }

        $membershipQuery = UserMembership::query()
            ->where('organization_id', $organizationId)
            ->where('username', $username);

        if ($ignoreMembershipId) {
            $membershipQuery->where('id', '!=', $ignoreMembershipId);
        }

        if ($membershipQuery->exists()) {
            throw ValidationException::withMessages([
                'username' => ['This username is already taken in your organization.'],
            ]);
        }
    }
}
