<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class UserAccountGuard
{
    public function assertCanDisableLogin(User $target, User $actor): void
    {
        if ((int) $target->id === (int) $actor->id) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot disable your own login.'],
            ]);
        }

        if ($target->is_admin && ! $actor->is_super_admin) {
            throw ValidationException::withMessages([
                'is_active' => ['Organization administrator accounts cannot have login disabled.'],
            ]);
        }

        if ($target->is_admin && $actor->is_super_admin) {
            $this->assertNotSoleOrgAdmin(
                $target,
                'is_active',
                'Cannot disable login for the only organization administrator. Promote another user first.',
            );
        }
    }

    public function assertCanDelete(User $target, User $actor): void
    {
        if ((int) $target->id === (int) $actor->id) {
            throw ValidationException::withMessages([
                'user' => ['You cannot delete your own account.'],
            ]);
        }

        // Org-admin flag blocks delete for tenant admins. Platform super-admins may
        // remove surplus org admins, but never the last one.
        if ($target->is_admin && ! $actor->is_super_admin) {
            throw ValidationException::withMessages([
                'user' => ['Organization administrator accounts cannot be deleted. Change their role away from Administrator first, or ask a platform admin.'],
            ]);
        }

        if ($target->is_admin && $actor->is_super_admin) {
            $this->assertNotSoleOrgAdmin(
                $target,
                'user',
                'Cannot delete the only organization administrator. Promote another user first.',
            );
        }
    }

    /**
     * Platform super-admins may promote/demote org admins.
     * Demoting the sole org admin is never allowed.
     */
    public function assertCanChangeOrgAdminFlag(User $target, User $actor, bool $makingAdmin): void
    {
        if (! $actor->is_super_admin) {
            throw ValidationException::withMessages([
                'is_admin' => ['Only a platform admin can change organization administrator status.'],
            ]);
        }

        if (! $makingAdmin) {
            $this->assertNotSoleOrgAdmin(
                $target,
                'is_admin',
                'Cannot remove the only organization administrator. Promote another user first.',
            );
        }
    }

    /**
     * Role demotion that would clear is_admin must leave at least one org admin.
     */
    public function assertCanClearOrgAdminFlag(User $target, User $actor): void
    {
        if (! $target->is_admin) {
            return;
        }

        $this->assertNotSoleOrgAdmin(
            $target,
            'is_admin',
            'Cannot demote the only organization administrator. Promote another user first, or ask a platform admin.',
        );
    }

    public function assertNotSoleOrgAdmin(User $target, string $field, string $message): void
    {
        if (! $target->is_admin) {
            return;
        }

        $hasOtherAdmin = User::query()
            ->where('organization_id', $target->organization_id)
            ->where('id', '!=', $target->id)
            ->where('is_admin', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasOtherAdmin) {
            throw ValidationException::withMessages([
                $field => [$message],
            ]);
        }
    }
}
