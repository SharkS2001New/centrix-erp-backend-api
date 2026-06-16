<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_payments')) {
            return;
        }

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('supplier_id');
            $table->integer('lpo_no')->nullable();
            $table->integer('payment_method_id');
            $table->decimal('amount_paid', 10, 2);
            $table->boolean('manual_amount')->default(false);
            $table->decimal('declared_payable', 10, 2)->nullable();
            $table->decimal('amount_due_snapshot', 10, 2)->nullable();
            $table->string('cheque_number', 45)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->date('date_paid');
            $table->integer('paid_by');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->foreign('payment_method_id')->references('id')->on('payment_methods');
            $table->foreign('paid_by')->references('id')->on('users');
            $table->index(['organization_id', 'supplier_id', 'date_paid']);
            $table->index(['lpo_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
