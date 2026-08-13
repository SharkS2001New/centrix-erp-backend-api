<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_clock_devices')) {
            Schema::table('attendance_clock_devices', function (Blueprint $table) {
                if (! Schema::hasColumn('attendance_clock_devices', 'agent_last_seen_at')) {
                    $table->dateTime('agent_last_seen_at')->nullable()->after('last_communication_at');
                }
                if (! Schema::hasColumn('attendance_clock_devices', 'agent_version')) {
                    $table->string('agent_version', 40)->nullable()->after('agent_last_seen_at');
                }
            });
        }

        if (! Schema::hasTable('hikvision_agent_commands')) {
            Schema::create('hikvision_agent_commands', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('attendance_clock_device_id');
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
                    ['attendance_clock_device_id', 'status', 'created_at'],
                    'hikvision_agent_cmd_device_status_idx',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hikvision_agent_commands');

        if (Schema::hasTable('attendance_clock_devices')) {
            Schema::table('attendance_clock_devices', function (Blueprint $table) {
                foreach (['agent_last_seen_at', 'agent_version'] as $col) {
                    if (Schema::hasColumn('attendance_clock_devices', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
