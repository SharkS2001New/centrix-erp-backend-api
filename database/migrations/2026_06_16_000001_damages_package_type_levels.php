<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE damages MODIFY package_type "
            ."ENUM('full_package','partial','pieces','full','middle','small') NOT NULL DEFAULT 'partial'"
        );
    }

    public function down(): void
    {
        DB::table('damages')->whereIn('package_type', ['full', 'middle', 'small'])->update([
            'package_type' => DB::raw("CASE package_type
                WHEN 'full' THEN 'full_package'
                WHEN 'middle' THEN 'partial'
                WHEN 'small' THEN 'pieces'
                ELSE package_type
            END"),
        ]);

        DB::statement(
            "ALTER TABLE damages MODIFY package_type "
            ."ENUM('full_package','partial','pieces') NOT NULL DEFAULT 'partial'"
        );
    }
};
