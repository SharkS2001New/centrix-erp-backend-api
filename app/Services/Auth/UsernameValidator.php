<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserMembership;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class UsernameValidator
{
    public const DUPLICATE_MESSAGE = 'Duplicate user: this username already exists in your organization.';

    public const DUPLICATE_DELETED_MESSAGE = 'Duplicate user: this username belongs to a deleted account. Restore that user or choose a different username.';

    public function assertUniqueInOrganization(
        int $organizationId,
        string $username,
        ?int $ignoreUserId = null,
        ?int $ignoreMembershipId = null,
    ): void {
        $username = UsernameNormalizer::forLookup($username);
        if ($username === '') {
            return;
        }

        // Include soft-deleted rows — uq_org_username still applies to them.
        $userQuery = User::withTrashed()
            ->where('organization_id', $organizationId)
            ->whereUsernameInsensitive($username);

        if ($ignoreUserId) {
            $userQuery->where('id', '!=', $ignoreUserId);
        }

        $existing = $userQuery->first(['id', 'deleted_at']);
        if ($existing) {
            throw ValidationException::withMessages([
                'username' => [
                    $existing->trashed()
                        ? self::DUPLICATE_DELETED_MESSAGE
                        : self::DUPLICATE_MESSAGE,
                ],
            ]);
        }

        $membershipQuery = UserMembership::query()
            ->where('organization_id', $organizationId)
            ->whereRaw('UPPER(username) = ?', [$username]);

        if ($ignoreMembershipId) {
            $membershipQuery->where('id', '!=', $ignoreMembershipId);
        }
        if ($ignoreUserId) {
            $membershipQuery->where('user_id', '!=', $ignoreUserId);
        }

        if ($membershipQuery->exists()) {
            throw ValidationException::withMessages([
                'username' => [self::DUPLICATE_MESSAGE],
            ]);
        }
    }

    /**
     * Convert a users.uq_org_username (or similar) DB collision into a 422 validation error.
     */
    public function rethrowIfDuplicateUsername(UniqueConstraintViolationException $e): void
    {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'uq_org_username')
            || str_contains($message, 'users.organization_id, users.username')
            || (str_contains($message, 'duplicate') && str_contains($message, 'username'))
            || (str_contains($message, 'unique') && str_contains($message, 'username') && str_contains($message, 'users'))) {
            throw ValidationException::withMessages([
                'username' => [self::DUPLICATE_MESSAGE],
            ]);
        }
    }
}
