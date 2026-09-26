<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User thumbs feedback on assistant replies — feeds Platform → AI usage / training review.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_assistant_feedback')) {
            return;
        }

        Schema::create('ai_assistant_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->uuid('conversation_id')->nullable()->index();
            $table->string('rating', 16); // up | down
            $table->string('workspace_id', 40)->nullable();
            $table->string('pathname', 300)->nullable();
            $table->text('user_message_preview')->nullable();
            $table->text('assistant_message_preview')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_feedback');
    }
};
