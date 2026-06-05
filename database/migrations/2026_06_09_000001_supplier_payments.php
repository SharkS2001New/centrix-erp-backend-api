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
            $table->integer('supplier_id');
            $table->integer('lpo_no')->nullable();
            $table->integer('lpo_supplier_invoice_id')->nullable();
            $table->integer('payment_method_id');
            $table->decimal('amount_paid', 12, 2);
            $table->decimal('amount_due_snapshot', 12, 2)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('cheque_number', 45)->nullable();
            $table->date('date_paid');
            $table->integer('paid_by');
            $table->integer('organization_id');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->foreign('lpo_no')->references('lpo_no')->on('lpo_mst');
            $table->foreign('lpo_supplier_invoice_id')->references('id')->on('lpo_supplier_invoices');
            $table->foreign('payment_method_id')->references('id')->on('payment_methods');
            $table->foreign('paid_by')->references('id')->on('users');
            $table->foreign('organization_id')->references('id')->on('organizations');

            $table->index(['supplier_id', 'date_paid']);
            $table->index('lpo_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
