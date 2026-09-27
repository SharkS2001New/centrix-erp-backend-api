<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expenses') && ! Schema::hasColumn('expenses', 'mobile_route_expense_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('mobile_route_expense_id')->nullable()->after('dispatch_trip_id');
                $table->unique('mobile_route_expense_id', 'expenses_mobile_route_expense_id_unique');
            });
        }

        if (! Schema::hasTable('mobile_route_expenses') || ! Schema::hasColumn('expenses', 'mobile_route_expense_id')) {
            return;
        }

        // Backfill non-rejected mobile expenses into the shared Accounting expenses list.
        $rows = DB::table('mobile_route_expenses')
            ->where('status', '!=', 'rejected')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $exists = DB::table('expenses')
                ->where('mobile_route_expense_id', $row->id)
                ->exists();
            if ($exists) {
                continue;
            }

            $orgId = (int) $row->organization_id;
            $branchId = (int) ($row->branch_id ?: 0);
            if ($branchId <= 0) {
                $branchId = (int) (DB::table('branches')
                    ->where('organization_id', $orgId)
                    ->orderBy('id')
                    ->value('id') ?: 0);
            }
            if ($branchId <= 0) {
                continue;
            }

            $groupId = $this->resolveMobileExpenseGroupId($orgId);
            $paymentMethodId = $this->resolveCashPaymentMethodId($orgId);
            if ($groupId <= 0 || $paymentMethodId <= 0) {
                continue;
            }

            DB::table('expenses')->insert([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'expense_group_id' => $groupId,
                'mobile_route_expense_id' => (int) $row->id,
                'description' => mb_substr((string) $row->description, 0, 200),
                'expense_amount' => $row->expense_amount,
                'expense_date' => $row->expense_date,
                'invoice_no' => 'MOBILE-'.$row->id,
                'payment_method_id' => $paymentMethodId,
                'recorded_by' => (int) $row->user_id,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'mobile_route_expense_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropUnique('expenses_mobile_route_expense_id_unique');
                $table->dropColumn('mobile_route_expense_id');
            });
        }
    }

    protected function resolveMobileExpenseGroupId(int $organizationId): int
    {
        if ($organizationId <= 0 || ! Schema::hasTable('expense_groups')) {
            return 0;
        }

        $existing = DB::table('expense_groups')
            ->where('organization_id', $organizationId)
            ->where('group_name', 'Mobile route')
            ->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $fallback = DB::table('expense_groups')
            ->where('organization_id', $organizationId)
            ->whereIn('group_name', ['Fuel', 'Other', 'Transport'])
            ->orderByRaw("FIELD(group_name, 'Fuel', 'Other', 'Transport')")
            ->value('id');
        if ($fallback) {
            return (int) $fallback;
        }

        return (int) DB::table('expense_groups')->insertGetId([
            'group_name' => 'Mobile route',
            'organization_id' => $organizationId,
        ]);
    }

    protected function resolveCashPaymentMethodId(int $organizationId): int
    {
        if ($organizationId <= 0 || ! Schema::hasTable('payment_methods')) {
            return 0;
        }

        $exact = DB::table('payment_methods')
            ->where('organization_id', $organizationId)
            ->whereRaw('UPPER(TRIM(method_code)) = ?', ['CASH'])
            ->value('id');
        if ($exact) {
            return (int) $exact;
        }

        return (int) (DB::table('payment_methods')
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->value('id') ?: 0);
    }
};
