<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mpesa_paybill_accounts')) {
            Schema::table('mpesa_paybill_accounts', function (Blueprint $table) {
                if (! Schema::hasColumn('mpesa_paybill_accounts', 'pos_till_id')) {
                    $table->unsignedBigInteger('pos_till_id')->nullable()->after('route_id')->index();
                }
                if (! Schema::hasColumn('mpesa_paybill_accounts', 'enable_stk_push')) {
                    $table->boolean('enable_stk_push')->nullable()->after('is_active');
                }
            });
        }

        if (Schema::hasTable('tills') && ! Schema::hasColumn('tills', 'mpesa_paybill_account_id')) {
            Schema::table('tills', function (Blueprint $table) {
                $table->unsignedBigInteger('mpesa_paybill_account_id')->nullable()->after('lock_mode')->index();
            });
        }

        if (Schema::hasTable('mpesa_incoming_payments')
            && ! Schema::hasColumn('mpesa_incoming_payments', 'matched_till_id')) {
            Schema::table('mpesa_incoming_payments', function (Blueprint $table) {
                $table->unsignedBigInteger('matched_till_id')->nullable()->after('matched_route_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mpesa_incoming_payments')
            && Schema::hasColumn('mpesa_incoming_payments', 'matched_till_id')) {
            Schema::table('mpesa_incoming_payments', function (Blueprint $table) {
                $table->dropColumn('matched_till_id');
            });
        }

        if (Schema::hasTable('tills') && Schema::hasColumn('tills', 'mpesa_paybill_account_id')) {
            Schema::table('tills', function (Blueprint $table) {
                $table->dropColumn('mpesa_paybill_account_id');
            });
        }

        if (Schema::hasTable('mpesa_paybill_accounts')) {
            Schema::table('mpesa_paybill_accounts', function (Blueprint $table) {
                if (Schema::hasColumn('mpesa_paybill_accounts', 'enable_stk_push')) {
                    $table->dropColumn('enable_stk_push');
                }
                if (Schema::hasColumn('mpesa_paybill_accounts', 'pos_till_id')) {
                    $table->dropColumn('pos_till_id');
                }
            });
        }
    }
};
