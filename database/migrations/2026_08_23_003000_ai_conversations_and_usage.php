<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_conversations')) {
            Schema::create('ai_conversations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('organization_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('title', 200)->nullable();
                $table->string('provider', 40)->nullable();
                $table->string('model', 120)->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('ai_conversation_messages')) {
            Schema::create('ai_conversation_messages', function (Blueprint $table) {
                $table->id();
                $table->uuid('conversation_id')->index();
                $table->unsignedBigInteger('organization_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('role', 20);
                $table->text('content');
                $table->json('tool_calls')->nullable();
                $table->json('tool_results')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'id']);
            });
        }

        if (! Schema::hasTable('ai_usage_logs')) {
            Schema::create('ai_usage_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->uuid('conversation_id')->nullable()->index();
                $table->string('provider', 40);
                $table->string('model', 120)->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('total_tokens')->default(0);
                $table->string('status', 40)->default('ok');
                $table->string('error_code', 80)->nullable();
                $table->text('error_message')->nullable();
                $table->json('tools_used')->nullable();
                $table->unsignedSmallInteger('latency_ms')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('ai_conversation_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
