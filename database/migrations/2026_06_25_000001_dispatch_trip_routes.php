<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dispatch_trip_routes')) {
            return;
        }

        Schema::create('dispatch_trip_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id');
            $table->integer('route_id');
            $table->timestamps();

            $table->foreign('trip_id')->references('id')->on('dispatch_trips')->cascadeOnDelete();
            $table->foreign('route_id')->references('id')->on('routes')->cascadeOnDelete();
            $table->unique(['trip_id', 'route_id']);
            $table->index('route_id');
        });

        $now = now();
        $rows = DB::table('dispatch_trips')
            ->whereNotNull('route_id')
            ->select('id as trip_id', 'route_id')
            ->get()
            ->map(fn ($row) => [
                'trip_id' => $row->trip_id,
                'route_id' => $row->route_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('dispatch_trip_routes')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_trip_routes');
    }
};
