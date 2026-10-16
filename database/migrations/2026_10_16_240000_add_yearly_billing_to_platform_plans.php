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

        Schema::table('platform_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('platform_plans', 'yearly_billing')) {
                $table->string('yearly_billing', 20)->nullable()->after('interval');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_plans') || ! Schema::hasColumn('platform_plans', 'yearly_billing')) {
            return;
        }

        Schema::table('platform_plans', function (Blueprint $table) {
            $table->dropColumn('yearly_billing');
        });
    }
};
