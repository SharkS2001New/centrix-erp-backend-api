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
                if (! Schema::hasColumn('attendance_clock_devices', 'device_name')) {
                    $table->string('device_name', 200)->nullable()->after('device_no');
                }
                if (! Schema::hasColumn('attendance_clock_devices', 'device_info_json')) {
                    $table->json('device_info_json')->nullable()->after('last_sync_error');
                }
                if (! Schema::hasColumn('attendance_clock_devices', 'capabilities_json')) {
                    $table->json('capabilities_json')->nullable()->after('device_info_json');
                }
                if (! Schema::hasColumn('attendance_clock_devices', 'capabilities_fetched_at')) {
                    $table->dateTime('capabilities_fetched_at')->nullable()->after('capabilities_json');
                }
                if (! Schema::hasColumn('attendance_clock_devices', 'last_event_serial')) {
                    $table->string('last_event_serial', 64)->nullable()->after('last_event_at');
                }
                if (! Schema::hasColumn('attendance_clock_devices', 'last_communication_at')) {
                    $table->dateTime('last_communication_at')->nullable()->after('last_synced_at');
                }
            });
        }

        if (! Schema::hasTable('hikvision_access_events')) {
            Schema::create('hikvision_access_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('attendance_clock_device_id');
                $table->string('event_key', 128);
                $table->string('employee_no', 64)->nullable();
                $table->string('employee_name', 200)->nullable();
                $table->dateTime('event_time');
                $table->unsignedSmallInteger('major')->nullable();
                $table->unsignedSmallInteger('minor')->nullable();
                $table->string('attendance_status', 40)->nullable();
                $table->string('verification_method', 80)->nullable();
                $table->string('card_no', 64)->nullable();
                $table->string('serial_no', 64)->nullable();
                $table->json('raw_payload');
                $table->dateTime('processed_at')->nullable();
                $table->unsignedBigInteger('clock_session_id')->nullable();
                $table->dateTime('created_at')->useCurrent();

                $table->unique(['attendance_clock_device_id', 'event_key'], 'hikvision_events_device_key_uq');
                $table->index(['organization_id', 'event_time'], 'hikvision_events_org_time_idx');
                $table->index(['attendance_clock_device_id', 'event_time'], 'hikvision_events_device_time_idx');
            });
        }

        if (! Schema::hasTable('hikvision_employee_mappings')) {
            Schema::create('hikvision_employee_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('attendance_clock_device_id');
                $table->unsignedBigInteger('employee_id');
                $table->string('hikvision_employee_no', 64);
                $table->string('sync_status', 40)->default('mapped');
                $table->dateTime('last_synced_at')->nullable();
                $table->dateTime('created_at')->useCurrent();
                $table->dateTime('updated_at')->nullable();

                $table->unique(
                    ['attendance_clock_device_id', 'hikvision_employee_no'],
                    'hikvision_map_device_empno_uq'
                );
                $table->unique(
                    ['attendance_clock_device_id', 'employee_id'],
                    'hikvision_map_device_employee_uq'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hikvision_employee_mappings');
        Schema::dropIfExists('hikvision_access_events');

        if (Schema::hasTable('attendance_clock_devices')) {
            Schema::table('attendance_clock_devices', function (Blueprint $table) {
                foreach ([
                    'device_name',
                    'device_info_json',
                    'capabilities_json',
                    'capabilities_fetched_at',
                    'last_event_serial',
                    'last_communication_at',
                ] as $col) {
                    if (Schema::hasColumn('attendance_clock_devices', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
