<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_plans') && ! Schema::hasColumn('platform_plans', 'cta_action')) {
            Schema::table('platform_plans', function (Blueprint $table) {
                $table->string('cta_action', 24)->default('buy_now');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_plans') && Schema::hasColumn('platform_plans', 'cta_action')) {
            Schema::table('platform_plans', function (Blueprint $table) {
                $table->dropColumn('cta_action');
            });
        }
    }
};
