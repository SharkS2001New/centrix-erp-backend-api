<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_licence_balances')) {
            return;
        }

        if (Schema::hasColumn('platform_licence_balances', 'token_alert_level')) {
            return;
        }

        $afterBalance = Schema::hasColumn('platform_licence_balances', 'token_balance');
        Schema::table('platform_licence_balances', function (Blueprint $table) use ($afterBalance) {
            $column = $table->unsignedTinyInteger('token_alert_level')->default(0);
            if ($afterBalance) {
                $column->after('token_balance');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_licence_balances')) {
            return;
        }

        Schema::table('platform_licence_balances', function (Blueprint $table) {
            if (Schema::hasColumn('platform_licence_balances', 'token_alert_level')) {
                $table->dropColumn('token_alert_level');
            }
        });
    }
};
