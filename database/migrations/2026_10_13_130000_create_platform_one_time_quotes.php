<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_one_time_quotes')) {
            return;
        }

        Schema::create('platform_one_time_quotes', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 32)->default('requested');
            $table->json('emailed_to')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_one_time_quotes');
    }
};
