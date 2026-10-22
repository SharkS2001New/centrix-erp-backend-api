<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_speed_api_minutes')) {
            Schema::create('platform_speed_api_minutes', function (Blueprint $table) {
                $table->id();
                $table->dateTime('minute_at')->index();
                $table->string('method', 12);
                $table->string('route_key', 220);
                $table->unsignedInteger('count')->default(0);
                $table->unsignedInteger('error_count')->default(0);
                $table->unsignedInteger('timeout_count')->default(0);
                $table->unsignedBigInteger('total_ms')->default(0);
                $table->unsignedInteger('max_ms')->default(0);
                $table->unsignedBigInteger('total_bytes')->default(0);
                $table->json('histogram')->nullable();
                $table->unique(['minute_at', 'method', 'route_key'], 'ps_api_minute_route_uq');
            });
        }

        if (! Schema::hasTable('platform_speed_query_minutes')) {
            Schema::create('platform_speed_query_minutes', function (Blueprint $table) {
                $table->id();
                $table->dateTime('minute_at')->index();
                $table->string('fingerprint', 64)->index();
                $table->string('sql_preview', 500);
                $table->unsignedInteger('count')->default(0);
                $table->unsignedInteger('slow_count')->default(0);
                $table->unsignedBigInteger('total_ms')->default(0);
                $table->unsignedInteger('max_ms')->default(0);
                $table->unsignedInteger('n_plus_one_hits')->default(0);
                $table->unique(['minute_at', 'fingerprint'], 'ps_query_minute_fp_uq');
            });
        }

        if (! Schema::hasTable('platform_speed_infra_minutes')) {
            Schema::create('platform_speed_infra_minutes', function (Blueprint $table) {
                $table->id();
                $table->dateTime('minute_at')->unique();
                $table->unsignedInteger('php_memory_mb')->nullable();
                $table->unsignedInteger('php_peak_memory_mb')->nullable();
                $table->decimal('load_1', 8, 2)->nullable();
                $table->decimal('disk_used_percent', 5, 2)->nullable();
                $table->unsignedInteger('redis_memory_mb')->nullable();
                $table->unsignedInteger('redis_ping_ms')->nullable();
                $table->unsignedInteger('mysql_threads_connected')->nullable();
                $table->unsignedInteger('queue_pending')->nullable();
                $table->unsignedInteger('queue_failed')->nullable();
                $table->json('meta')->nullable();
            });
        }

        if (! Schema::hasTable('platform_speed_synthetic_checks')) {
            Schema::create('platform_speed_synthetic_checks', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('method', 12)->default('GET');
                $table->string('path', 400);
                $table->unsignedInteger('interval_seconds')->default(60);
                $table->unsignedInteger('timeout_ms')->default(5000);
                $table->unsignedSmallInteger('expect_status')->default(200);
                $table->string('auth_mode', 32)->default('none'); // none|token
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('platform_speed_synthetic_results')) {
            Schema::create('platform_speed_synthetic_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('check_id')->index();
                $table->timestamp('ran_at')->index();
                $table->boolean('ok')->default(false);
                $table->unsignedInteger('status_code')->nullable();
                $table->unsignedInteger('total_ms')->nullable();
                $table->unsignedInteger('dns_ms')->nullable();
                $table->unsignedInteger('connect_ms')->nullable();
                $table->unsignedInteger('tls_ms')->nullable();
                $table->unsignedInteger('ttfb_ms')->nullable();
                $table->string('error', 500)->nullable();
                $table->foreign('check_id')
                    ->references('id')
                    ->on('platform_speed_synthetic_checks')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_speed_synthetic_results');
        Schema::dropIfExists('platform_speed_synthetic_checks');
        Schema::dropIfExists('platform_speed_infra_minutes');
        Schema::dropIfExists('platform_speed_query_minutes');
        Schema::dropIfExists('platform_speed_api_minutes');
    }
};
