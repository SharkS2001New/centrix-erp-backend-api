<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_billing_settings')) {
            return;
        }

        $missingPercent = ! Schema::hasColumn('platform_billing_settings', 'duration_discount_percent');
        $missingMonths = ! Schema::hasColumn('platform_billing_settings', 'duration_discount_min_months');
        if (! $missingPercent && ! $missingMonths) {
            return;
        }

        Schema::table('platform_billing_settings', function (Blueprint $table) use ($missingPercent, $missingMonths) {
            if ($missingPercent) {
                $table->decimal('duration_discount_percent', 5, 2)->default(0);
            }
            if ($missingMonths) {
                $table->unsignedSmallInteger('duration_discount_min_months')->default(3);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_billing_settings')) {
            return;
        }

        Schema::table('platform_billing_settings', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('platform_billing_settings', 'duration_discount_percent')) {
                $drop[] = 'duration_discount_percent';
            }
            if (Schema::hasColumn('platform_billing_settings', 'duration_discount_min_months')) {
                $drop[] = 'duration_discount_min_months';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
