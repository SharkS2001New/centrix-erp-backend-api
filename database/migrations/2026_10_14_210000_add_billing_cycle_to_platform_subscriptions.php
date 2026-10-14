<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_subscriptions') || Schema::hasColumn('platform_subscriptions', 'billing_cycle')) {
            return;
        }

        Schema::table('platform_subscriptions', function (Blueprint $table) {
            $table->string('billing_cycle', 16)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_subscriptions') || ! Schema::hasColumn('platform_subscriptions', 'billing_cycle')) {
            return;
        }

        Schema::table('platform_subscriptions', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });
    }
};
