<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_attendance') && Schema::hasColumn('employee_attendance', 'source')) {
            DB::statement("ALTER TABLE employee_attendance MODIFY COLUMN source ENUM(
                'manual','clock_device','company_mobile'
            ) NOT NULL DEFAULT 'manual'");
        }

        if (Schema::hasTable('employee_clock_sessions')) {
            Schema::table('employee_clock_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_clock_sessions', 'source')) {
                    $table->string('source', 32)->default('clock_device')->after('branch_id');
                }
                if (! Schema::hasColumn('employee_clock_sessions', 'clock_in_latitude')) {
                    $table->decimal('clock_in_latitude', 10, 7)->nullable()->after('device_identifier');
                    $table->decimal('clock_in_longitude', 10, 7)->nullable()->after('clock_in_latitude');
                    $table->string('clock_in_address', 500)->nullable()->after('clock_in_longitude');
                    $table->string('clock_in_photo_path', 500)->nullable()->after('clock_in_address');
                    $table->decimal('clock_in_face_match_score', 5, 4)->nullable()->after('clock_in_photo_path');
                    $table->decimal('clock_in_geofence_distance_metres', 8, 2)->nullable()->after('clock_in_face_match_score');
                }
                if (! Schema::hasColumn('employee_clock_sessions', 'clock_out_latitude')) {
                    $table->decimal('clock_out_latitude', 10, 7)->nullable()->after('clock_in_geofence_distance_metres');
                    $table->decimal('clock_out_longitude', 10, 7)->nullable()->after('clock_out_latitude');
                    $table->string('clock_out_address', 500)->nullable()->after('clock_out_longitude');
                    $table->string('clock_out_photo_path', 500)->nullable()->after('clock_out_address');
                    $table->decimal('clock_out_face_match_score', 5, 4)->nullable()->after('clock_out_photo_path');
                    $table->decimal('clock_out_geofence_distance_metres', 8, 2)->nullable()->after('clock_out_face_match_score');
                }
            });
        }

        // A failed prior run may have created this table without foreign keys (unsigned vs signed INT mismatch).
        Schema::dropIfExists('employee_face_profiles');

        if (! Schema::hasTable('employee_face_profiles')) {
            Schema::create('employee_face_profiles', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('employee_id')->unique();
                $table->integer('organization_id');
                $table->string('enrollment_photo_path', 500);
                $table->json('face_embedding');
                $table->timestamp('enrolled_at');
                $table->string('enrolled_device_identifier', 100)->nullable();

                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
                $table->foreign('organization_id')->references('id')->on('organizations');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_face_profiles');

        if (Schema::hasTable('employee_attendance') && Schema::hasColumn('employee_attendance', 'source')) {
            DB::table('employee_attendance')->where('source', 'company_mobile')->update(['source' => 'manual']);
            DB::statement("ALTER TABLE employee_attendance MODIFY COLUMN source ENUM(
                'manual','clock_device'
            ) NOT NULL DEFAULT 'manual'");
        }

        if (Schema::hasTable('employee_clock_sessions')) {
            Schema::table('employee_clock_sessions', function (Blueprint $table) {
                foreach ([
                    'source',
                    'clock_in_latitude', 'clock_in_longitude', 'clock_in_address', 'clock_in_photo_path',
                    'clock_in_face_match_score', 'clock_in_geofence_distance_metres',
                    'clock_out_latitude', 'clock_out_longitude', 'clock_out_address', 'clock_out_photo_path',
                    'clock_out_face_match_score', 'clock_out_geofence_distance_metres',
                ] as $column) {
                    if (Schema::hasColumn('employee_clock_sessions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
