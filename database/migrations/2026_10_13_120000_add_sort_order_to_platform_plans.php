<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_plans') || Schema::hasColumn('platform_plans', 'sort_order')) {
            return;
        }

        Schema::table('platform_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_plans') || ! Schema::hasColumn('platform_plans', 'sort_order')) {
            return;
        }

        Schema::table('platform_plans', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
