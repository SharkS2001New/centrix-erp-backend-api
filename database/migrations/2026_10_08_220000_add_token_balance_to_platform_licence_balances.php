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

        Schema::table('platform_licence_balances', function (Blueprint $table) {
            if (! Schema::hasColumn('platform_licence_balances', 'token_balance')) {
                $table->unsignedInteger('token_balance')->default(0)->after('balance');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_licence_balances')) {
            return;
        }

        Schema::table('platform_licence_balances', function (Blueprint $table) {
            if (Schema::hasColumn('platform_licence_balances', 'token_balance')) {
                $table->dropColumn('token_balance');
            }
        });
    }
};
