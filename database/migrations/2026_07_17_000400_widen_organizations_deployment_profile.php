<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * organizations.deployment_profile was a narrow ENUM that rejected newer
 * profiles (e.g. hotel_bar, supermarket, custom). Align with provisioning
 * templates as varchar(60).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations') || ! Schema::hasColumn('organizations', 'deployment_profile')) {
            return;
        }

        DB::statement("ALTER TABLE organizations MODIFY deployment_profile VARCHAR(60) NOT NULL DEFAULT 'wholesale_retail'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('organizations') || ! Schema::hasColumn('organizations', 'deployment_profile')) {
            return;
        }

        // Map unknown profiles back to wholesale_retail before restoring ENUM.
        DB::table('organizations')
            ->whereNotIn('deployment_profile', ['small_shop', 'wholesale_retail', 'distribution'])
            ->update(['deployment_profile' => 'wholesale_retail']);

        DB::statement("ALTER TABLE organizations MODIFY deployment_profile ENUM('small_shop','wholesale_retail','distribution') NOT NULL DEFAULT 'wholesale_retail'");
    }
};
