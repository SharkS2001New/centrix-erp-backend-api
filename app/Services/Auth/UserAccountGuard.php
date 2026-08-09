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

        if ($target->is_admin) {
            throw ValidationException::withMessages([
                'is_active' => ['Organization administrator accounts cannot have login disabled.'],
            ]);
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
        // remove org admins (e.g. test accounts); the seeded org admin is not special-cased.
        if ($target->is_admin && ! $actor->is_super_admin) {
            throw ValidationException::withMessages([
                'user' => ['Organization administrator accounts cannot be deleted. Change their role away from Administrator first, or ask a platform admin.'],
            ]);
        }
    }
}
