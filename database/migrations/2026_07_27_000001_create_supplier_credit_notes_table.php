<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_credit_notes')) {
            return;
        }

        Schema::create('supplier_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('organization_id');
            $table->unsignedInteger('credit_note_seq');
            $table->string('credit_note_no', 20);
            $table->unsignedInteger('supplier_id');
            $table->unsignedInteger('branch_id');
            $table->date('credit_date');
            $table->decimal('total_amount', 14, 2);
            $table->string('reason', 500);
            $table->text('description')->nullable();
            $table->string('supplier_invoice_no', 100)->nullable();
            $table->unsignedInteger('lpo_no')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending_approval', 'approved', 'rejected'])->default('pending_approval');
            $table->unsignedInteger('created_by');
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'credit_note_seq'], 'uq_org_supplier_credit_note_seq');
            $table->unique(['organization_id', 'credit_note_no'], 'uq_org_supplier_credit_note_no');
            $table->index(['organization_id', 'status']);
            $table->index(['supplier_id', 'credit_date']);
            $table->index('branch_id');
        });

        Schema::create('supplier_credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_credit_note_id');
            $table->string('product_code', 200)->nullable();
            $table->string('product_name', 200)->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->unsignedSmallInteger('line_no')->nullable();
            $table->timestamps();

            $table->foreign('supplier_credit_note_id')
                ->references('id')
                ->on('supplier_credit_notes')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_note_lines');
        Schema::dropIfExists('supplier_credit_notes');
    }
};
