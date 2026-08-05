<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay-now hotels (folios off) track in-house guests on the room row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitality_rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('hospitality_rooms', 'guest_name')) {
                $table->string('guest_name', 160)->nullable()->after('status');
            }
            if (! Schema::hasColumn('hospitality_rooms', 'guest_phone')) {
                $table->string('guest_phone', 40)->nullable()->after('guest_name');
            }
            if (! Schema::hasColumn('hospitality_rooms', 'checked_in_at')) {
                $table->dateTime('checked_in_at')->nullable()->after('guest_phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hospitality_rooms', function (Blueprint $table) {
            foreach (['checked_in_at', 'guest_phone', 'guest_name'] as $col) {
                if (Schema::hasColumn('hospitality_rooms', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
