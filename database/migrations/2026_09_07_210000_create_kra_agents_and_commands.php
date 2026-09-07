<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop-PC KRA agent: cloud Centrix enqueues fiscal HTTP calls; the LAN agent
 * polls and proxies them to local Comstore (e.g. http://127.0.0.1:4000).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kra_agents')) {
            Schema::create('kra_agents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->unique();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('name', 120)->default('CentrixKraAgent');
                $table->string('comstore_base_url', 250)->default('http://127.0.0.1:4000');
                $table->dateTime('agent_last_seen_at')->nullable();
                $table->string('agent_version', 40)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('kra_agent_commands')) {
            Schema::create('kra_agent_commands', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('kra_agent_id')->index();
                $table->string('method', 10);
                $table->string('path', 500);
                $table->json('body_json')->nullable();
                $table->string('accept', 40)->default('json');
                $table->string('status', 20)->default('pending');
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->json('response_headers')->nullable();
                $table->mediumText('response_body')->nullable();
                $table->string('error_message', 500)->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('completed_at')->nullable();
                $table->dateTime('expires_at');

                $table->index(
                    ['kra_agent_id', 'status', 'created_at'],
                    'kra_agent_cmd_agent_status_idx',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kra_agent_commands');
        Schema::dropIfExists('kra_agents');
    }
};
