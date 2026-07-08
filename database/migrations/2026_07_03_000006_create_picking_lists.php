<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'shelf_location')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('shelf_location', 50)->nullable()->after('stock_in_store');
            });
        }

        if (! Schema::hasTable('picking_lists')) {
            Schema::create('picking_lists', function (Blueprint $table) {
                $table->id();
                $table->integer('branch_id');
                $table->unsignedBigInteger('trip_id');
                $table->integer('route_id')->nullable();
                $table->date('list_date');
                $table->string('list_number', 50);
                $table->enum('status', ['open', 'completed', 'locked'])->default('open');
                $table->string('picker_name', 200)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->timestamps();

                $table->foreign('branch_id')->references('id')->on('branches');
                $table->foreign('trip_id')->references('id')->on('dispatch_trips')->cascadeOnDelete();
                $table->foreign('route_id')->references('id')->on('routes')->nullOnDelete();
                $table->unique('trip_id');
                $table->unique(['branch_id', 'list_number']);
            });
        }

        if (! Schema::hasTable('picking_list_lines')) {
            Schema::create('picking_list_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('picking_list_id');
                $table->unsignedSmallInteger('line_no');
                $table->string('product_code', 200);
                $table->string('product_name', 255);
                $table->string('shelf_location', 50)->nullable();
                $table->enum('stock_location', ['shop', 'store'])->default('store');
                $table->float('required_qty');
                $table->float('picked_qty')->default(0);
                $table->float('shortage_qty')->default(0);
                $table->string('quantity_label', 120)->nullable();
                $table->string('pack_breakdown', 200)->nullable();
                $table->string('shortage_reason', 255)->nullable();

                $table->foreign('picking_list_id')->references('id')->on('picking_lists')->cascadeOnDelete();
                $table->index(['picking_list_id', 'line_no']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('picking_list_lines');
        Schema::dropIfExists('picking_lists');

        if (Schema::hasColumn('products', 'shelf_location')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('shelf_location');
            });
        }
    }
};
