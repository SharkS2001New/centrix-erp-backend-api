<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'product_volume_m3')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('product_volume_m3', 12, 6)->nullable()->after('product_weight');
            });
        }

        if (! Schema::hasTable('branch_stock_transfers')) {
            Schema::create('branch_stock_transfers', function (Blueprint $table) {
                $table->id();
                $table->integer('organization_id');
                $table->integer('from_branch_id');
                $table->integer('to_branch_id');
                $table->string('product_code', 200);
                $table->double('quantity');
                $table->enum('from_location', ['shop', 'store'])->default('store');
                $table->enum('to_location', ['shop', 'store'])->default('store');
                $table->text('notes')->nullable();
                $table->integer('created_by');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['organization_id', 'created_at']);
                $table->index(['from_branch_id', 'to_branch_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_stock_transfers');

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'product_volume_m3')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('product_volume_m3');
            });
        }
    }
};
