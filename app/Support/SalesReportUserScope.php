<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Users eligible for sales report / sales-data cashier & salesperson filters:
 * backoffice order create, POS checkout, or mobile field sales create.
 */
class SalesReportUserScope
{
    /** @return list<string> */
    public static function permissionCodes(): array
    {
        return [
            'sales.orders.create',
            'pos.checkout.create',
            'pos.terminal.view',
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

        // Eligible when at least one create/POS permission is effectively granted
        // (role or grant override), and that same permission is not denied.
        $query->where(function ($outer) use ($permissionIds) {
            foreach ($permissionIds as $permissionId) {
                $outer->orWhere(function ($one) use ($permissionId) {
                    $one->where(function ($has) use ($permissionId) {
                        $has->whereExists(function ($sub) use ($permissionId) {
                            $sub->selectRaw('1')
                                ->from('role_permissions as rp')
                                ->whereColumn('rp.role_id', 'users.role_id')
                                ->where('rp.permission_id', $permissionId);
                        })->orWhereExists(function ($sub) use ($permissionId) {
                            $sub->selectRaw('1')
                                ->from('user_permission_overrides as upo')
                                ->whereColumn('upo.user_id', 'users.id')
                                ->where('upo.effect', 'grant')
                                ->where('upo.permission_id', $permissionId);
                        });
                    })->whereNotExists(function ($sub) use ($permissionId) {
                        $sub->selectRaw('1')
                            ->from('user_permission_overrides as upo')
                            ->whereColumn('upo.user_id', 'users.id')
                            ->where('upo.effect', 'deny')
                            ->where('upo.permission_id', $permissionId);
                    });
                });
            }
        });
    }
}
