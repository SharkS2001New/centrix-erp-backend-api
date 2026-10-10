<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_subscriptions')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (['current_period_start', 'current_period_end', 'trial_ends_at'] as $column) {
            if (! Schema::hasColumn('platform_subscriptions', $column)) {
                continue;
            }
            DB::statement("ALTER TABLE platform_subscriptions MODIFY {$column} DATETIME NULL");
        }

        // Old date columns became midnight. Keep those licences open until the end of that day.
        foreach (['current_period_end', 'trial_ends_at'] as $column) {
            if (! Schema::hasColumn('platform_subscriptions', $column)) {
                continue;
            }
            DB::table('platform_subscriptions')
                ->whereNotNull($column)
                ->whereRaw("TIME({$column}) = '00:00:00'")
                ->update([
                    $column => DB::raw("TIMESTAMP(DATE({$column}), '23:59:59')"),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_subscriptions') || Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (['current_period_start', 'current_period_end', 'trial_ends_at'] as $column) {
            if (! Schema::hasColumn('platform_subscriptions', $column)) {
                continue;
            }
            DB::statement("ALTER TABLE platform_subscriptions MODIFY {$column} DATE NULL");
        }
    }
};
