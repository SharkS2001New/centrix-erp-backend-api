<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_plans')) {
            return;
        }

        if (Schema::hasColumn('platform_plans', 'self_serve')) {
            return;
        }

        Schema::table('platform_plans', function (Blueprint $table) {
            $table->boolean('self_serve')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_plans') || ! Schema::hasColumn('platform_plans', 'self_serve')) {
            return;
        }

        Schema::table('platform_plans', function (Blueprint $table) {
            $table->dropColumn('self_serve');
        });
    }
};
