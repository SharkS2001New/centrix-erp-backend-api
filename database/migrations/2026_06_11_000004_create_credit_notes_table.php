<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('credit_notes')) {
            return;
        }

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('credit_note_no', 20)->unique();
            $table->unsignedBigInteger('customer_return_id')->unique();
            $table->integer('organization_id');
            $table->integer('branch_id');
            $table->bigInteger('sale_id')->nullable();
            $table->integer('customer_num')->nullable();
            $table->date('credit_date');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('refund_method', 45)->default('CASH');
            $table->string('reason', 200)->nullable();
            $table->text('notes')->nullable();
            $table->enum('kra_status', ['skipped', 'pending', 'success', 'failed'])->default('skipped');
            $table->string('kra_relevant_invoice_number', 64)->nullable();
            $table->string('kra_refund_reason_code', 4)->nullable();
            $table->string('kra_invoice_number', 255)->nullable();
            $table->string('kra_cu_inv_no', 64)->nullable();
            $table->text('kra_receipt_signature')->nullable();
            $table->text('kra_signature_link')->nullable();
            $table->string('kra_serial_number', 255)->nullable();
            $table->string('kra_timestamp', 255)->nullable();
            $table->json('kra_request_payload')->nullable();
            $table->json('kra_response_payload')->nullable();
            $table->text('kra_error_message')->nullable();
            $table->timestamps();

            $table->foreign('customer_return_id')->references('id')->on('customer_returns')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('sale_id')->references('id')->on('sales');
            $table->foreign('customer_num')->references('customer_num')->on('customers');
            $table->index(['organization_id', 'credit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
