<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Users eligible for sales report cashier / salesperson filters.
 */
class SalesReportUserScope
{
    /** @return list<string> */
    public static function permissionCodes(): array
    {
        return [
            'sales.orders.create',
            'pos.checkout.create',
            'mobile_sales.orders.create',
        ];
    }

    /**
     * @param  Builder<User>  $query
     */
    public static function applyEligibleSalesReportUsers(Builder $query): void
    {
        $permissionIds = Permission::query()
            ->whereIn('permission_code', self::permissionCodes())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($permissionIds === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where(function ($outer) use ($permissionIds) {
            $outer->whereExists(function ($sub) use ($permissionIds) {
                $sub->selectRaw('1')
                    ->from('role_permissions as rp')
                    ->whereColumn('rp.role_id', 'users.role_id')
                    ->whereIn('rp.permission_id', $permissionIds);
            })->orWhereExists(function ($sub) use ($permissionIds) {
                $sub->selectRaw('1')
                    ->from('user_permission_overrides as upo')
                    ->whereColumn('upo.user_id', 'users.id')
                    ->where('upo.effect', 'grant')
                    ->whereIn('upo.permission_id', $permissionIds);
            });
        });

        $query->whereNotExists(function ($sub) use ($permissionIds) {
            $sub->selectRaw('1')
                ->from('user_permission_overrides as upo')
                ->whereColumn('upo.user_id', 'users.id')
                ->where('upo.effect', 'deny')
                ->whereIn('upo.permission_id', $permissionIds);
        });
    }
}
