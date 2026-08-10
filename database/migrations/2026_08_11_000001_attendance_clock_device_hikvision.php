<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_clock_devices')) {
            return;
        }

        Schema::table('attendance_clock_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_clock_devices', 'provider')) {
                $table->string('provider', 40)->default('generic')->after('is_active');
            }
            if (! Schema::hasColumn('attendance_clock_devices', 'host')) {
                $table->string('host', 255)->nullable()->after('provider');
            }
            if (! Schema::hasColumn('attendance_clock_devices', 'port')) {
                $table->unsignedSmallInteger('port')->nullable()->after('host');
            }
            if (! Schema::hasColumn('attendance_clock_devices', 'username')) {
                $table->string('username', 100)->nullable()->after('port');
            }
            if (! Schema::hasColumn('attendance_clock_devices', 'password_encrypted')) {
                $table->text('password_encrypted')->nullable()->after('username');
            }
            if (! Schema::hasColumn('attendance_clock_devices', 'use_https')) {
                $table->boolean('use_https')->default(false)->after('password_encrypted');
            }
            if (! Schema::hasColumn('attendance_clock_devices', 'last_synced_at')) {
                $table->dateTime('last_synced_at')->nullable()->after('use_https');
            }
            if (! Schema::hasColumn('attendance_clock_devices', 'last_event_at')) {
                $table->dateTime('last_event_at')->nullable()->after('last_synced_at');
            }
            if (! Schema::hasColumn('attendance_clock_devices', 'last_sync_error')) {
                $table->string('last_sync_error', 500)->nullable()->after('last_event_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_clock_devices')) {
            return;
        }

        Schema::table('attendance_clock_devices', function (Blueprint $table) {
            foreach ([
                'provider',
                'host',
                'port',
                'username',
                'password_encrypted',
                'use_https',
                'last_synced_at',
                'last_event_at',
                'last_sync_error',
            ] as $col) {
                if (Schema::hasColumn('attendance_clock_devices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
