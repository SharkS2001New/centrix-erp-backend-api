<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('platform_subscriptions', 'reminder_log')) {
                $table->json('reminder_log')->nullable()->after('invoice_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('platform_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('platform_subscriptions', 'reminder_log')) {
                $table->dropColumn('reminder_log');
            }
        });
    }
};
