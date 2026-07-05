<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_handoffs')) {
            Schema::create('whatsapp_handoffs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->unsignedInteger('customer_num')->nullable();
                $table->string('phone', 20);
                $table->string('status', 20)->default('open');
                $table->text('customer_message')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'status', 'created_at']);
                $table->index(['conversation_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_handoffs');
    }
};
