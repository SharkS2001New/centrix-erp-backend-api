<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hikvision_access_events')) {
            return;
        }

        Schema::table('hikvision_access_events', function (Blueprint $table) {
            if (! Schema::hasColumn('hikvision_access_events', 'process_error')) {
                $table->string('process_error', 500)->nullable()->after('processed_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hikvision_access_events')) {
            return;
        }

        Schema::table('hikvision_access_events', function (Blueprint $table) {
            if (Schema::hasColumn('hikvision_access_events', 'process_error')) {
                $table->dropColumn('process_error');
            }
        });
    }
};
