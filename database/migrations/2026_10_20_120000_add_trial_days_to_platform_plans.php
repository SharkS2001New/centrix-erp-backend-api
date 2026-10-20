<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_plans') || Schema::hasColumn('platform_plans', 'trial_days')) {
            return;
        }

        Schema::table('platform_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->nullable()->after('seat_limit');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_plans') || ! Schema::hasColumn('platform_plans', 'trial_days')) {
            return;
        }

        Schema::table('platform_plans', function (Blueprint $table) {
            $table->dropColumn('trial_days');
        });
    }
};
