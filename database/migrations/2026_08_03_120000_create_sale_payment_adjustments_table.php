<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_payment_adjustments')) {
            return;
        }

        Schema::create('sale_payment_adjustments', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('sale_id');
            $table->integer('payment_method_id');
            $table->decimal('amount', 15, 2);
            $table->enum('adjustment_type', ['return', 'topup']);
            $table->string('reference_number', 120)->nullable();
            $table->unsignedBigInteger('float_session_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('payment_method_id')->references('id')->on('payment_methods');
            $table->index(['sale_id', 'adjustment_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payment_adjustments');
    }
};
