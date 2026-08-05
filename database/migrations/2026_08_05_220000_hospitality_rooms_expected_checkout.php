<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitality_rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('hospitality_rooms', 'expected_checkout_at')) {
                $table->dateTime('expected_checkout_at')->nullable()->after('checked_in_at');
                $table->index(['organization_id', 'expected_checkout_at'], 'hosp_rooms_checkout_idx');
            }
            if (! Schema::hasColumn('hospitality_rooms', 'sold_check_id')) {
                $table->unsignedBigInteger('sold_check_id')->nullable()->after('expected_checkout_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hospitality_rooms', function (Blueprint $table) {
            if (Schema::hasColumn('hospitality_rooms', 'sold_check_id')) {
                $table->dropColumn('sold_check_id');
            }
            if (Schema::hasColumn('hospitality_rooms', 'expected_checkout_at')) {
                $table->dropIndex('hosp_rooms_checkout_idx');
                $table->dropColumn('expected_checkout_at');
            }
        });
    }
};
