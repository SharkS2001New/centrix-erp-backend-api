<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_issue_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind', 32);
            $table->string('status', 32)->default('open');
            $table->string('message', 500);
            $table->text('user_notes')->nullable();
            $table->string('page_url', 500)->nullable();
            $table->string('api_path', 500)->nullable();
            $table->string('http_method', 16)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('context')->nullable();
            $table->boolean('reported_by_user')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_issue_reports');
    }
};
