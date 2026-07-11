<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_bot_training_replies')) {
            return;
        }

        Schema::create('whatsapp_bot_training_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120)->nullable();
            $table->json('keywords');
            $table->text('response_text');
            $table->string('match_mode', 16)->default('any');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_bot_training_replies');
    }
};
