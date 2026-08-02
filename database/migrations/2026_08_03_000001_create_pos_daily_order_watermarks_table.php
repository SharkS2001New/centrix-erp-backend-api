<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_daily_order_watermarks')) {
            return;
        }

        Schema::create('pos_daily_order_watermarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('cashier_id');
            $table->date('pos_order_date');
            $table->unsignedInteger('watermark')->default(0);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'cashier_id', 'pos_order_date'],
                'uq_pos_daily_order_watermark',
            );
            $table->index(['organization_id', 'cashier_id'], 'idx_pos_daily_wm_org_cashier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_daily_order_watermarks');
    }
};
