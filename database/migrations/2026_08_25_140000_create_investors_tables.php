<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('investor_code', 40);
            $table->string('investor_name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'investor_code']);
        });

        Schema::create('investor_contributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('investor_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            /** cash = deposited money; stock = investor paid supplier for goods */
            $table->string('contribution_type', 20);
            $table->date('contribution_date');
            $table->decimal('amount', 18, 2)->default(0);
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('payment_code', 120)->nullable()->index();
            $table->string('reference_number', 120)->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->unsignedBigInteger('lpo_no')->nullable()->index();
            $table->unsignedBigInteger('supplier_payment_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        /** Funded product batches — shared SKUs allowed via separate rows per investor/contribution */
        Schema::create('investor_product_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('investor_id')->index();
            $table->unsignedBigInteger('contribution_id')->index();
            $table->string('product_code', 64)->index();
            $table->string('product_name')->nullable();
            $table->string('packaging')->nullable();
            $table->decimal('qty_purchased', 18, 4)->default(0);
            $table->decimal('qty_remaining', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->unsignedBigInteger('lpo_no')->nullable()->index();
            $table->unsignedBigInteger('lpo_txn_id')->nullable();
            $table->unsignedBigInteger('stock_receipt_id')->nullable();
            $table->date('received_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'product_code']);
            $table->index(['investor_id', 'product_code']);
        });

        /** Cash-pool spends: supplier payments, expenses, etc. */
        Schema::create('investor_spend_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('investor_id')->index();
            $table->unsignedBigInteger('contribution_id')->nullable()->index();
            $table->string('spend_type', 40);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_label')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->date('spend_date');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'spend_type', 'reference_id'], 'inv_spend_org_type_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_spend_links');
        Schema::dropIfExists('investor_product_batches');
        Schema::dropIfExists('investor_contributions');
        Schema::dropIfExists('investors');
    }
};
