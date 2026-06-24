<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

class AttendanceSchema
{
    public static function hasCompanyMobileSessions(): bool
    {
        return Schema::hasTable('employee_clock_sessions')
            && Schema::hasColumn('employee_clock_sessions', 'source');
    }

    public static function hasMobileDevices(): bool
    {
        return Schema::hasTable('attendance_mobile_devices');
    }

    public static function hasBranchPremises(): bool
    {
        return Schema::hasTable('attendance_branch_premises');
    }

    public static function hasFaceProfiles(): bool
    {
        return Schema::hasTable('employee_face_profiles');
    }

    public static function hasFingerprintProfiles(): bool
    {
        return Schema::hasTable('employee_fingerprint_profiles');
    }
}
