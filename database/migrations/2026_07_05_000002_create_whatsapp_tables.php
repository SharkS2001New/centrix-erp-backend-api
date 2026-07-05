<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_configs')) {
            Schema::create('whatsapp_configs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('bot_user_id')->nullable();
                $table->string('phone_number_id', 64)->nullable();
                $table->string('waba_id', 64)->nullable();
                $table->string('display_phone', 32)->nullable();
                $table->text('access_token')->nullable();
                $table->string('webhook_verify_token', 120)->nullable();
                $table->string('graph_api_version', 16)->default('v21.0');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique('organization_id');
                $table->index(['phone_number_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('whatsapp_conversations')) {
            Schema::create('whatsapp_conversations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->string('phone', 20);
                $table->unsignedInteger('customer_num')->nullable();
                $table->string('state', 40)->default('main_menu');
                $table->json('payload')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'phone']);
                $table->index(['organization_id', 'state']);
            });
        }

        if (! Schema::hasTable('whatsapp_message_logs')) {
            Schema::create('whatsapp_message_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->string('provider_message_id', 120)->nullable();
                $table->string('direction', 8);
                $table->string('from_phone', 20)->nullable();
                $table->text('body')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['organization_id', 'provider_message_id']);
                $table->index(['conversation_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_logs');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_configs');
    }
};
