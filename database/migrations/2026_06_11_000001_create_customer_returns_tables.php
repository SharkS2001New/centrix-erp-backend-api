<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_returns')) {
            return;
        }

        Schema::create('customer_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 20)->unique();
            $table->integer('organization_id');
            $table->integer('branch_id');
            $table->bigInteger('sale_id')->nullable();
            $table->integer('customer_num')->nullable();
            $table->date('return_date');
            $table->string('refund_method', 45)->default('CASH');
            $table->string('reason', 200)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->enum('stock_location', ['shop', 'store'])->default('shop');
            $table->integer('returned_by');
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('sale_id')->references('id')->on('sales');
            $table->foreign('customer_num')->references('customer_num')->on('customers');
            $table->foreign('returned_by')->references('id')->on('users');
            $table->foreign('approved_by')->references('id')->on('users');
            $table->foreign('rejected_by')->references('id')->on('users');
            $table->index(['organization_id', 'status']);
            $table->index('return_date');
        });

        Schema::create('customer_return_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_return_id');
            $table->bigInteger('sale_item_id')->nullable();
            $table->string('product_code', 200);
            $table->string('product_name', 200)->nullable();
            $table->float('quantity_sold')->default(0);
            $table->float('return_qty');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('line_no')->nullable();

            $table->foreign('customer_return_id')->references('id')->on('customer_returns')->cascadeOnDelete();
            $table->index('product_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_return_lines');
        Schema::dropIfExists('customer_returns');
    }
};
