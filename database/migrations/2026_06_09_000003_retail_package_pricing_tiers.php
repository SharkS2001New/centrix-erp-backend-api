<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retail_package_settings')) {
            return;
        }

        if (! Schema::hasColumn('retail_package_settings', 'pricing_tiers')) {
            Schema::table('retail_package_settings', function (Blueprint $table) {
                $table->json('pricing_tiers')->nullable()->after('max_uom_measure');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('retail_package_settings', 'pricing_tiers')) {
            Schema::table('retail_package_settings', function (Blueprint $table) {
                $table->dropColumn('pricing_tiers');
            });
        }
    }
};
