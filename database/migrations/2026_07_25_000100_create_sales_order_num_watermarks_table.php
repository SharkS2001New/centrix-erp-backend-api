<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * High-water mark for reserved POS order numbers (offline sell pool), per org.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_order_num_watermarks')) {
            return;
        }

        Schema::create('sales_order_num_watermarks', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->primary();
            $table->unsignedInteger('watermark')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_num_watermarks');
    }
};
