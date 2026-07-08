<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manager_device_tokens') || Schema::hasTable('user_device_tokens')) {
            return;
        }

        Schema::create('manager_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('token', 512);
            $table->string('platform', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'token']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_device_tokens');
    }
};
