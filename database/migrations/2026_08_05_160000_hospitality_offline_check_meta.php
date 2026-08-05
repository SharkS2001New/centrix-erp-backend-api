<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotel & Bar POS offline sell → sync: client uuid stamp + check-number watermark
 * (same pattern as External POS order-number reserves).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hospitality_checks') && ! Schema::hasColumn('hospitality_checks', 'meta')) {
            Schema::table('hospitality_checks', function (Blueprint $table) {
                $table->json('meta')->nullable()->after('guest_name');
            });
        }

        if (! Schema::hasTable('hospitality_check_num_watermarks')) {
            Schema::create('hospitality_check_num_watermarks', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->primary();
                $table->unsignedBigInteger('watermark')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hospitality_check_num_watermarks');

        if (Schema::hasTable('hospitality_checks') && Schema::hasColumn('hospitality_checks', 'meta')) {
            Schema::table('hospitality_checks', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }
    }
};
