<?php

namespace App\Services\Erp;

use App\Models\TillFloatSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TillSessionAuthorization
{
    public static function assertCanView(User $user, TillFloatSession $session): void
    {
        if (self::canView($user, $session)) {
            return;
        }

        throw new AccessDeniedHttpException('You cannot access this till session.');
    }

    public static function assertCanHandover(User $user, TillFloatSession $session): void
    {
        if ($user->is_admin || self::hasPermission($user, 'sales.manage')) {
            return;
        }

        throw new AccessDeniedHttpException('Only a manager can hand over a till session.');
    }

    public static function assertSessionCashier(User $user, TillFloatSession $session): void
    {
        if ($user->is_admin || (int) $session->cashier_id === (int) $user->id) {
            return;
        }

        throw new AccessDeniedHttpException('Only the session cashier can perform this action.');
    }

    public static function canView(User $user, TillFloatSession $session): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if ((int) $session->cashier_id === (int) $user->id) {
            return true;
        }

        return self::hasPermission($user, 'sales.manage');
    }

    public static function canCorrectFloat(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return self::hasPermission($user, 'sales.manage');
    }

    protected static function hasPermission(User $user, string $permission): bool
    {
        if (! $user->role_id) {
            return false;
        }

        return DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $user->role_id)
            ->where('permissions.permission_code', $permission)
            ->exists();
    }
}
