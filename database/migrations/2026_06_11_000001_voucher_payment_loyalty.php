<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vouchers') && ! Schema::hasColumn('vouchers', 'voucher_kind')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->enum('voucher_kind', ['discount', 'payment'])->default('discount')->after('voucher_code');
                $table->double('initial_balance')->default(0)->after('discount_value');
                $table->double('balance')->default(0)->after('initial_balance');
            });
        }

        if (! Schema::hasTable('loyalty_cards')) {
            Schema::create('loyalty_cards', function (Blueprint $table) {
                $table->id();
                $table->integer('organization_id');
                $table->integer('customer_num');
                $table->string('card_number', 32);
                $table->string('phone_number', 45);
                $table->double('points_balance')->default(0);
                $table->boolean('is_active')->default(true);
                $table->date('issued_at')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps();

                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->foreign('customer_num')->references('customer_num')->on('customers');
                $table->foreign('created_by')->references('id')->on('users');
                $table->unique(['organization_id', 'card_number'], 'uq_org_loyalty_card');
                $table->index(['organization_id', 'phone_number'], 'idx_loyalty_phone');
                $table->index(['organization_id', 'customer_num'], 'idx_loyalty_customer');
            });
        }

        if (Schema::hasTable('temporary_carts') && ! Schema::hasColumn('temporary_carts', 'payment_voucher_id')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_voucher_id')->nullable()->after('order_discount');
                $table->double('voucher_payment_amount')->default(0)->after('payment_voucher_id');
                $table->unsignedBigInteger('loyalty_card_id')->nullable()->after('voucher_payment_amount');
                $table->double('points_redeemed')->default(0)->after('loyalty_card_id');
                $table->double('points_payment_amount')->default(0)->after('points_redeemed');
                $table->string('mpesa_phone', 45)->nullable()->after('points_payment_amount');
            });
        }

        if (Schema::hasTable('sales') && ! Schema::hasColumn('sales', 'voucher_payment_amount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->double('voucher_payment_amount')->default(0)->after('order_discount');
                $table->double('points_payment_amount')->default(0)->after('voucher_payment_amount');
                $table->unsignedBigInteger('loyalty_card_id')->nullable()->after('points_payment_amount');
            });
        }

        if (Schema::hasTable('payment_methods')) {
            $exists = DB::table('payment_methods')->whereIn('method_code', ['VOUCHER', 'POINTS'])->count();
            if ($exists < 2) {
                DB::table('payment_methods')->insertOrIgnore([
                    ['method_name' => 'Voucher', 'method_code' => 'VOUCHER', 'requires_reference' => true, 'is_active' => true],
                    ['method_name' => 'Loyalty points', 'method_code' => 'POINTS', 'requires_reference' => true, 'is_active' => true],
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('temporary_carts') && Schema::hasColumn('temporary_carts', 'payment_voucher_id')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                $table->dropColumn([
                    'payment_voucher_id',
                    'voucher_payment_amount',
                    'loyalty_card_id',
                    'points_redeemed',
                    'points_payment_amount',
                    'mpesa_phone',
                ]);
            });
        }

        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'voucher_payment_amount')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn(['voucher_payment_amount', 'points_payment_amount', 'loyalty_card_id']);
            });
        }

        Schema::dropIfExists('loyalty_cards');

        if (Schema::hasTable('vouchers') && Schema::hasColumn('vouchers', 'voucher_kind')) {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->dropColumn(['voucher_kind', 'initial_balance', 'balance']);
            });
        }
    }
};
