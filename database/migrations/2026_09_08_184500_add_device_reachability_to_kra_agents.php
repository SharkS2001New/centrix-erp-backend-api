<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kra_agents')) {
            return;
        }

        Schema::table('kra_agents', function (Blueprint $table) {
            if (! Schema::hasColumn('kra_agents', 'device_reachable')) {
                $table->boolean('device_reachable')->nullable()->after('comstore_status_message');
            }
            if (! Schema::hasColumn('kra_agents', 'device_status_message')) {
                $table->string('device_status_message', 500)->nullable()->after('device_reachable');
            }
            if (! Schema::hasColumn('kra_agents', 'device_hardware_ip')) {
                $table->string('device_hardware_ip', 100)->nullable()->after('device_status_message');
            }
            if (! Schema::hasColumn('kra_agents', 'device_connection')) {
                $table->string('device_connection', 80)->nullable()->after('device_hardware_ip');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kra_agents')) {
            return;
        }

        Schema::table('kra_agents', function (Blueprint $table) {
            foreach (['device_connection', 'device_hardware_ip', 'device_status_message', 'device_reachable'] as $col) {
                if (Schema::hasColumn('kra_agents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
