<?php

namespace App\Services\Auth;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UserLoginService
{
    public function disableLogin(User $user): User
    {
        $user->forceFill(['is_active' => false])->save();
        $user->tokens()->delete();

        return $user->fresh();
    }

    public function assertCanEnableLogin(User $user): void
    {
        $blocked = Employee::query()
            ->where('user_id', $user->id)
            ->where(function ($query) {
                $query->where('is_active', false)
                    ->orWhere('employment_status', '!=', 'active');
            })
            ->exists();

        if ($blocked) {
            throw ValidationException::withMessages([
                'is_active' => ['Cannot enable login while the linked employee record is inactive.'],
            ]);
        }
    }

    public function syncFromEmployee(Employee $employee): void
    {
        if (! $employee->user_id) {
            return;
        }

        $user = User::find($employee->user_id);
        if (! $user) {
            return;
        }

        if ($user->is_admin) {
            return;
        }

        if (! $employee->is_active || $employee->employment_status !== 'active') {
            $this->disableLogin($user);
        }
    }
}
