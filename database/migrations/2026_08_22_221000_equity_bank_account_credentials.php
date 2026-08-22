<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-account Equity callback URL / shared secret.
 * Blank fields inherit organization finance.equity defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('equity_bank_accounts')) {
            return;
        }

        Schema::table('equity_bank_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('equity_bank_accounts', 'callback_url')) {
                $table->string('callback_url', 500)->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('equity_bank_accounts', 'callback_shared_secret')) {
                $table->text('callback_shared_secret')->nullable()->after('callback_url');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('equity_bank_accounts')) {
            return;
        }

        Schema::table('equity_bank_accounts', function (Blueprint $table) {
            foreach (['callback_url', 'callback_shared_secret'] as $col) {
                if (Schema::hasColumn('equity_bank_accounts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
