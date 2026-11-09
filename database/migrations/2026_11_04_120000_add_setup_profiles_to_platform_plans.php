<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_plans') || Schema::hasColumn('platform_plans', 'setup_profile_keys')) {
            return;
        }

        Schema::table('platform_plans', function (Blueprint $table) {
            $table->json('setup_profile_keys')->nullable()->after('trial_profile_keys');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_plans') || ! Schema::hasColumn('platform_plans', 'setup_profile_keys')) {
            return;
        }

        Schema::table('platform_plans', function (Blueprint $table) {
            $table->dropColumn('setup_profile_keys');
        });
    }
};
