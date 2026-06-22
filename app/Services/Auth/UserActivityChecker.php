<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserActivityChecker
{
    /** @var list<array{0: string, 1: string}> */
    protected array $activityReferences = [
        ['sales', 'cashier_id'],
        ['sales', 'cancelled_by'],
        ['sales', 'deleted_by'],
        ['till_float_sessions', 'cashier_id'],
        ['customer_payments', 'recorded_by'],
        ['supplier_payments', 'paid_by'],
        ['journal_entries', 'created_by'],
        ['expenses', 'created_by'],
        ['stock_receipts', 'received_by'],
        ['stock_adjustments', 'created_by'],
        ['stock_moves', 'moved_by'],
        ['damages', 'reported_by'],
        ['customer_return_documents', 'returned_by'],
        ['supplier_return_documents', 'returned_by'],
        ['stock_take_sessions', 'started_by'],
        ['stock_take_sessions', 'completed_by'],
        ['payroll_runs', 'processed_by'],
        ['payroll_runs', 'approved_by'],
        ['payroll_runs', 'paid_by'],
        ['mobile_rep_attendance_sessions', 'user_id'],
        ['employees', 'user_id'],
    ];

    public function hasRetainedActivity(int $userId): bool
    {
        foreach ($this->activityReferences as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::table($table)->where($column, $userId)->exists()) {
                return true;
            }
        }

        return false;
    }
}
