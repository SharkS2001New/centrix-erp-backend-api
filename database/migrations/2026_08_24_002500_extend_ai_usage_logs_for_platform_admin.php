<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_usage_logs')) {
            return;
        }

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_usage_logs', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('ai_usage_logs', 'estimated_cost')) {
                $table->decimal('estimated_cost', 12, 6)->default(0)->after('total_tokens');
            }
            if (! Schema::hasColumn('ai_usage_logs', 'prompt_preview')) {
                $table->string('prompt_preview', 2000)->nullable()->after('error_message');
            }
            if (! Schema::hasColumn('ai_usage_logs', 'response_preview')) {
                $table->string('response_preview', 2000)->nullable()->after('prompt_preview');
            }
            if (! Schema::hasColumn('ai_usage_logs', 'worker')) {
                $table->string('worker', 120)->nullable()->after('latency_ms');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_usage_logs')) {
            return;
        }

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            foreach (['branch_id', 'estimated_cost', 'prompt_preview', 'response_preview', 'worker'] as $col) {
                if (Schema::hasColumn('ai_usage_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
