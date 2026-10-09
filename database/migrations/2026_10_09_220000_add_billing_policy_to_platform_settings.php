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
        if (Schema::hasColumn('platform_billing_settings', 'policy')) {
            return;
        }

        Schema::table('platform_billing_settings', function (Blueprint $table) {
            $table->json('policy')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_billing_settings') || ! Schema::hasColumn('platform_billing_settings', 'policy')) {
            return;
        }

        Schema::table('platform_billing_settings', function (Blueprint $table) {
            $table->dropColumn('policy');
        });
    }
};
