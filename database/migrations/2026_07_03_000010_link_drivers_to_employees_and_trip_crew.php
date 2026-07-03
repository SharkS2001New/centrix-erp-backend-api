<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drivers')) {
            Schema::table('drivers', function (Blueprint $table) {
                if (! Schema::hasColumn('drivers', 'employee_id')) {
                    $table->integer('employee_id')->nullable()->after('user_id');
                }
            });

            $this->addUniqueIfMissing('drivers', 'drivers_user_id_unique', 'user_id');
            $this->addUniqueIfMissing('drivers', 'drivers_employee_id_unique', 'employee_id');

            if (! $this->foreignKeyExists('drivers', 'drivers_employee_id_foreign')) {
                Schema::table('drivers', function (Blueprint $table) {
                    $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
                });
            }
        }

        if (Schema::hasTable('mobile_driver_attendance_sessions')) {
            Schema::table('mobile_driver_attendance_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('mobile_driver_attendance_sessions', 'employee_id')) {
                    $table->integer('employee_id')->nullable()->after('driver_id');
                }
            });

            if (! $this->foreignKeyExists('mobile_driver_attendance_sessions', 'mobile_driver_attendance_employee_id_foreign')) {
                Schema::table('mobile_driver_attendance_sessions', function (Blueprint $table) {
                    $table->foreign('employee_id', 'mobile_driver_attendance_employee_id_foreign')
                        ->references('id')
                        ->on('employees')
                        ->nullOnDelete();
                });
            }
        }

        if (! Schema::hasTable('dispatch_trip_crew')) {
            Schema::create('dispatch_trip_crew', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('trip_id');
                $table->integer('employee_id');
                $table->string('role', 40)->default('turn_boy');
                $table->timestamps();

                $table->foreign('trip_id')->references('id')->on('dispatch_trips')->cascadeOnDelete();
                $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
                $table->unique(['trip_id', 'employee_id'], 'uq_dispatch_trip_crew_employee');
                $table->index(['employee_id', 'role'], 'idx_dispatch_trip_crew_employee_role');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_trip_crew');

        if (Schema::hasTable('mobile_driver_attendance_sessions') && Schema::hasColumn('mobile_driver_attendance_sessions', 'employee_id')) {
            if ($this->foreignKeyExists('mobile_driver_attendance_sessions', 'mobile_driver_attendance_employee_id_foreign')) {
                Schema::table('mobile_driver_attendance_sessions', function (Blueprint $table) {
                    $table->dropForeign('mobile_driver_attendance_employee_id_foreign');
                });
            }
            Schema::table('mobile_driver_attendance_sessions', function (Blueprint $table) {
                $table->dropColumn('employee_id');
            });
        }

        if (Schema::hasTable('drivers') && Schema::hasColumn('drivers', 'employee_id')) {
            if ($this->foreignKeyExists('drivers', 'drivers_employee_id_foreign')) {
                Schema::table('drivers', function (Blueprint $table) {
                    $table->dropForeign('drivers_employee_id_foreign');
                });
            }
            $this->dropIndexIfExists('drivers', 'drivers_employee_id_unique');
            $this->dropIndexIfExists('drivers', 'drivers_user_id_unique');
            Schema::table('drivers', function (Blueprint $table) {
                $table->dropColumn('employee_id');
            });
        }
    }

    protected function addUniqueIfMissing(string $table, string $index, string $column): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$index}` (`{$column}`)");
    }

    protected function dropIndexIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }

    protected function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    protected function foreignKeyExists(string $table, string $constraint): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', $database)
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
