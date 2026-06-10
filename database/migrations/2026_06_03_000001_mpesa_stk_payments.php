<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('temporary_carts')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                if (! Schema::hasColumn('temporary_carts', 'mpesa_payment_amount')) {
                    $table->double('mpesa_payment_amount')->default(0)->after('mpesa_phone');
                }
                if (! Schema::hasColumn('temporary_carts', 'mpesa_transaction_code')) {
                    $table->string('mpesa_transaction_code', 45)->nullable()->after('mpesa_payment_amount');
                }
            });
        }

        if (! Schema::hasTable('mpesa_stk_requests')) {
            Schema::create('mpesa_stk_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cart_id');
                $table->unsignedBigInteger('organization_id');
                $table->string('phone_number', 45);
                $table->unsignedInteger('amount');
                $table->string('merchant_request_id', 64)->nullable();
                $table->string('checkout_request_id', 64)->nullable()->unique();
                $table->string('transaction_id', 45)->nullable();
                $table->unsignedInteger('paid_amount')->nullable();
                $table->string('status', 20)->default('pending');
                $table->unsignedSmallInteger('result_code')->nullable();
                $table->string('result_desc', 255)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['cart_id', 'status']);
                $table->index(['organization_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_stk_requests');

        if (Schema::hasTable('temporary_carts')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                if (Schema::hasColumn('temporary_carts', 'mpesa_transaction_code')) {
                    $table->dropColumn('mpesa_transaction_code');
                }
                if (Schema::hasColumn('temporary_carts', 'mpesa_payment_amount')) {
                    $table->dropColumn('mpesa_payment_amount');
                }
            });
        }
    }
};
