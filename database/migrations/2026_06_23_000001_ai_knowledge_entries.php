<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->integer('organization_id');
            $table->integer('created_by')->nullable();
            $table->string('source', 32);
            $table->string('topic', 200);
            $table->string('path', 200)->nullable();
            $table->text('content');
            $table->boolean('confirmed')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->integer('confirmed_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'confirmed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_entries');
    }
};
