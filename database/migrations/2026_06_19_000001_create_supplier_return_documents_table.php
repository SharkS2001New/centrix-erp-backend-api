<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_return_documents')) {
            return;
        }

        Schema::create('supplier_return_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('organization_id');
            $table->unsignedInteger('supplier_id');
            $table->unsignedInteger('branch_id');
            $table->enum('source_type', ['lpo', 'manual'])->default('manual');
            $table->unsignedInteger('lpo_no')->nullable();
            $table->string('supplier_invoice_no', 100)->nullable();
            $table->enum('reason_scope', ['order', 'per_product'])->default('order');
            $table->text('return_reason')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending_approval', 'approved', 'rejected'])->default('pending_approval');
            $table->unsignedInteger('returned_by');
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['supplier_id', 'lpo_no']);
            $table->index('branch_id');
        });

        Schema::create('supplier_return_document_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('product_code', 200);
            $table->string('product_name', 200)->nullable();
            $table->double('quantity');
            $table->enum('package_type', ['full_package', 'partial', 'pieces'])->default('partial');
            $table->string('package_type_label', 100)->nullable();
            $table->string('uom_label', 45)->nullable();
            $table->enum('stock_location', ['shop', 'store'])->default('store');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('lpo_txn_id')->nullable();
            $table->double('stock_deduct_qty')->nullable();
            $table->timestamps();

            $table->foreign('document_id')
                ->references('id')
                ->on('supplier_return_documents')
                ->cascadeOnDelete();
            $table->index('product_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_return_document_lines');
        Schema::dropIfExists('supplier_return_documents');
    }
};
