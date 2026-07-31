<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_assigned_routes')) {
        Schema::create('user_assigned_routes', function (Blueprint $table) {
            $table->id();
            // Match users.id / routes.id (signed INT) for MySQL FK compatibility.
            $table->integer('user_id');
            $table->integer('route_id');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('route_id')->references('id')->on('routes')->cascadeOnDelete();
            $table->unique(['user_id', 'route_id']);
            $table->index('route_id');
        });
        }

        if (! Schema::hasColumn('users', 'assigned_route_id')) {
            return;
        }

        $now = now();
        $rows = DB::table('users')
            ->whereNotNull('assigned_route_id')
            ->select('id as user_id', 'assigned_route_id as route_id')
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'route_id' => (int) $row->route_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('user_assigned_routes')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_assigned_routes');
    }
};
