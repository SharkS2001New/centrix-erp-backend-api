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

        if ($target->is_admin) {
            throw ValidationException::withMessages([
                'user' => ['Organization administrator accounts cannot be deleted.'],
            ]);
        }
    }
}
