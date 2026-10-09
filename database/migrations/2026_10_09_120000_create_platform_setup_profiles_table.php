<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_setup_profiles')) {
            return;
        }

        Schema::create('platform_setup_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('profile_key', 64)->unique();
            $table->json('menu_groups');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_setup_profiles');
    }
};
