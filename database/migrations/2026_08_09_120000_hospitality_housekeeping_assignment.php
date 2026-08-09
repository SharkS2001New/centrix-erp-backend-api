<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hospitality_rooms')) {
            return;
        }

        Schema::table('hospitality_rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('hospitality_rooms', 'housekeeping_assigned_to')) {
                $table->unsignedBigInteger('housekeeping_assigned_to')->nullable()->after('sold_check_id');
                $table->index(['organization_id', 'housekeeping_assigned_to'], 'hosp_rooms_hk_assignee_idx');
            }
            if (! Schema::hasColumn('hospitality_rooms', 'housekeeping_notes')) {
                $table->string('housekeeping_notes', 500)->nullable()->after('housekeeping_assigned_to');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hospitality_rooms')) {
            return;
        }

        Schema::table('hospitality_rooms', function (Blueprint $table) {
            if (Schema::hasColumn('hospitality_rooms', 'housekeeping_notes')) {
                $table->dropColumn('housekeeping_notes');
            }
            if (Schema::hasColumn('hospitality_rooms', 'housekeeping_assigned_to')) {
                $table->dropIndex('hosp_rooms_hk_assignee_idx');
                $table->dropColumn('housekeeping_assigned_to');
            }
        });
    }
};
