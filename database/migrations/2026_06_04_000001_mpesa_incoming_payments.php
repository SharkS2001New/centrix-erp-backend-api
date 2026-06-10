<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mpesa_incoming_payments')) {
            Schema::create('mpesa_incoming_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable();
                $table->string('transaction_id', 45)->unique();
                $table->string('phone_number', 45);
                $table->unsignedInteger('amount');
                $table->unsignedInteger('applied_amount')->nullable();
                $table->string('source', 20)->default('c2b');
                $table->string('status', 20)->default('available');
                $table->unsignedBigInteger('applied_cart_id')->nullable();
                $table->unsignedBigInteger('stk_request_id')->nullable();
                $table->timestamp('received_at')->useCurrent();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->index(['phone_number', 'status', 'received_at']);
                $table->index(['applied_cart_id', 'status']);
            });
        }

        if (! Schema::hasTable('mpesa_payment_skips')) {
            Schema::create('mpesa_payment_skips', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cart_id');
                $table->unsignedBigInteger('mpesa_incoming_payment_id');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['cart_id', 'mpesa_incoming_payment_id'], 'uq_cart_mpesa_skip');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_payment_skips');
        Schema::dropIfExists('mpesa_incoming_payments');
    }
};
