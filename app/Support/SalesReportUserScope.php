<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Users eligible for sales report / sales-data cashier & salesperson filters:
 * retail POS cashiers, Hotel POS cashiers, and mobile field sales.
 *
 * Backoffice-only order creators (sales.orders.create alone) are excluded so
 * End of Day and similar pickers stay limited to Cashiers + Mobile Sales users.
 */
class SalesReportUserScope
{
    /** @return list<string> */
    public static function permissionCodes(): array
    {
        return [
            'pos.checkout.create',
            'pos.terminal.view',
            // Hotel / bar POS cashiers settle checks — must appear on hospitality EOD filters.
            'hotel_bar_pos.checks.create',
            'hotel_bar_pos.terminal.view',
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

        $query->where(function ($eligible) use ($permissionIds) {
            if ($permissionIds !== []) {
                // Eligible when at least one POS / hotel POS / mobile-sales permission is
                // effectively granted (role or grant override), and not denied.
                $eligible->where(function ($outer) use ($permissionIds) {
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

            // Also include users explicitly tagged for POS or Mobile login,
            // even if their permission matrix is incomplete.
            $eligible->orWhere('is_mobile_user', true)
                ->orWhereJsonContains('login_channels', 'pos')
                ->orWhereJsonContains('login_channels', 'mobile');
        });
    }
}
