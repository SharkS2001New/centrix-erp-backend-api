<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'dispatch_trip_id')) {
                $table->unsignedBigInteger('dispatch_trip_id')->nullable()->after('float_session_id');
                $table->foreign('dispatch_trip_id')
                    ->references('id')
                    ->on('dispatch_trips')
                    ->nullOnDelete();
                $table->index('dispatch_trip_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('expenses') || ! Schema::hasColumn('expenses', 'dispatch_trip_id')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['dispatch_trip_id']);
            $table->dropIndex(['dispatch_trip_id']);
            $table->dropColumn('dispatch_trip_id');
        });
    }
};
