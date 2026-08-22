<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-paybill Daraja app credentials / callback URLs.
 * Blank fields inherit organization finance.mpesa defaults at STK / C2B time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mpesa_paybill_accounts')) {
            return;
        }

        Schema::table('mpesa_paybill_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('mpesa_paybill_accounts', 'env')) {
                $table->string('env', 20)->nullable()->after('enable_stk_push');
            }
            if (! Schema::hasColumn('mpesa_paybill_accounts', 'consumer_key')) {
                $table->string('consumer_key', 255)->nullable()->after('env');
            }
            if (! Schema::hasColumn('mpesa_paybill_accounts', 'consumer_secret')) {
                $table->text('consumer_secret')->nullable()->after('consumer_key');
            }
            if (! Schema::hasColumn('mpesa_paybill_accounts', 'passkey')) {
                $table->text('passkey')->nullable()->after('consumer_secret');
            }
            if (! Schema::hasColumn('mpesa_paybill_accounts', 'stk_callback_url')) {
                $table->string('stk_callback_url', 500)->nullable()->after('passkey');
            }
            if (! Schema::hasColumn('mpesa_paybill_accounts', 'c2b_confirmation_url')) {
                $table->string('c2b_confirmation_url', 500)->nullable()->after('stk_callback_url');
            }
            if (! Schema::hasColumn('mpesa_paybill_accounts', 'c2b_validation_url')) {
                $table->string('c2b_validation_url', 500)->nullable()->after('c2b_confirmation_url');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mpesa_paybill_accounts')) {
            return;
        }

        Schema::table('mpesa_paybill_accounts', function (Blueprint $table) {
            foreach ([
                'env',
                'consumer_key',
                'consumer_secret',
                'passkey',
                'stk_callback_url',
                'c2b_confirmation_url',
                'c2b_validation_url',
            ] as $col) {
                if (Schema::hasColumn('mpesa_paybill_accounts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
