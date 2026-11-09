<?php

namespace App\Support;

use App\Models\User;
use App\Services\Auth\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Users eligible for sales report / sales-data cashier & salesperson filters.
 *
 * Driven by each user's effective permissions (role + overrides), not by the
 * logged-in session permission list:
 * - backoffice create order (`sales.orders.create`)
 * - retail POS checkout
 * - Hotel / bar POS checks
 * - mobile field sales (`mobile_sales.orders.create`)
 *
 * Pickers also require an active login user and must not surface users linked
 * to inactive / terminated employees.
 */
class SalesReportUserScope
{
    /** @return list<string> */
    public static function permissionCodes(): array
    {
        return [
            'sales.orders.create',
            'pos.checkout.create',
            // Terminal access alone is enough for some locked POS cashiers.
            'pos.terminal.view',
            // Hotel / bar POS cashiers settle checks — must appear on hospitality EOD filters.
            'hotel_bar_pos.checks.create',
            'hotel_bar_pos.terminal.view',
            'mobile_sales.orders.create',
        ];
    }

    /**
     * Active accounts only — excludes deactivated users and users whose linked
     * employee record is inactive / terminated / retired.
     *
     * @param  Builder<User>  $query
     */
    public static function applyActivePickerUsers(Builder $query): void
    {
        CentrixAgentServiceUser::excludeFromQuery($query);

        $query->where('is_active', true);

        if (! Schema::hasTable('employees')) {
            return;
        }

        $query->whereNotExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('employees as e')
                ->whereColumn('e.user_id', 'users.id')
                ->where(function ($inactive) {
                    $inactive->where('e.is_active', false)
                        ->orWhereIn('e.employment_status', ['suspended', 'terminated', 'retired']);
                });
        });
    }

    /**
     * @param  Builder<User>  $query
     */
    public static function applyEligibleSalesReportUsers(Builder $query): void
    {
        $permissionIds = app(PermissionCatalog::class)
            ->idsForCodes(self::permissionCodes());

        if ($permissionIds === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        // Eligible when at least one create-order / POS / mobile-sales permission is
        // effectively granted (role or grant override), and that same permission is not denied.
        CentrixAgentServiceUser::excludeFromQuery($query);

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

    /**
     * Sales cashier / salesperson filter lists: sales-capable + active account.
     *
     * @param  Builder<User>  $query
     */
    public static function applySalesPickerUsers(Builder $query): void
    {
        self::applyEligibleSalesReportUsers($query);
        self::applyActivePickerUsers($query);
    }
}
