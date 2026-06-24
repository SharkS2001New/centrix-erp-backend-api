<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_branch_premises')) {
            Schema::create('attendance_branch_premises', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('organization_id');
                $table->integer('branch_id');
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->decimal('radius_metres', 8, 2)->nullable();
                $table->integer('updated_by')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->foreign('branch_id')->references('id')->on('branches');
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
                $table->unique(['organization_id', 'branch_id'], 'uq_attendance_branch_premises');
            });
        }

        if (Schema::hasTable('attendance_mobile_devices') && ! Schema::hasColumn('attendance_mobile_devices', 'branch_id')) {
            Schema::table('attendance_mobile_devices', function (Blueprint $table) {
                $table->integer('branch_id')->nullable()->after('organization_id');
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            });
        }

        $this->migrateLegacyOrgPremises();
        $this->assignLegacyDeviceBranches();
    }

    public function down(): void
    {
        if (Schema::hasTable('attendance_mobile_devices') && Schema::hasColumn('attendance_mobile_devices', 'branch_id')) {
            Schema::table('attendance_mobile_devices', function (Blueprint $table) {
                $table->dropForeign(['branch_id']);
                $table->dropColumn('branch_id');
            });
        }

        Schema::dropIfExists('attendance_branch_premises');
    }

    protected function migrateLegacyOrgPremises(): void
    {
        if (! Schema::hasTable('organizations') || ! Schema::hasTable('branches')) {
            return;
        }

        $organizations = DB::table('organizations')->get(['id', 'module_settings']);
        foreach ($organizations as $org) {
            $settings = json_decode($org->module_settings ?? '{}', true);
            $hr = is_array($settings['hr_payroll'] ?? null) ? $settings['hr_payroll'] : [];
            $lat = $hr['company_premises_latitude'] ?? null;
            $lng = $hr['company_premises_longitude'] ?? null;
            if ($lat === null || $lng === null) {
                continue;
            }

            $radius = $hr['company_premises_radius_metres'] ?? 5;
            $branches = DB::table('branches')
                ->where('organization_id', $org->id)
                ->pluck('id');

            foreach ($branches as $branchId) {
                DB::table('attendance_branch_premises')->insertOrIgnore([
                    'organization_id' => $org->id,
                    'branch_id' => $branchId,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'radius_metres' => $radius,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    protected function assignLegacyDeviceBranches(): void
    {
        if (! Schema::hasTable('attendance_mobile_devices') || ! Schema::hasColumn('attendance_mobile_devices', 'branch_id')) {
            return;
        }

        $devices = DB::table('attendance_mobile_devices')->whereNull('branch_id')->get(['id', 'organization_id']);
        foreach ($devices as $device) {
            $branchId = DB::table('branches')
                ->where('organization_id', $device->organization_id)
                ->orderBy('id')
                ->value('id');

            if ($branchId) {
                DB::table('attendance_mobile_devices')
                    ->where('id', $device->id)
                    ->update(['branch_id' => $branchId]);
            }
        }
    }
};
