<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_permission_overrides')) {
            return;
        }

        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->integer('user_id');
            $table->integer('permission_id');
            $table->enum('effect', ['grant', 'deny']);
            $table->primary(['user_id', 'permission_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
