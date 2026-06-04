<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy patch for databases created before schema.sql included customer geo/shop image.
 * Fresh installs via 0002_01_01_000000_create_pos_erp_schema already have these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'latitude')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable()->after('town');
            });
        }

        if (! Schema::hasColumn('customers', 'longitude')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }

        if (! Schema::hasColumn('customers', 'shop_image')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('shop_image', 255)->nullable()->after('longitude');
            });
        }
    }

    public function down(): void
    {
        $drops = [];
        foreach (['shop_image', 'longitude', 'latitude'] as $col) {
            if (Schema::hasColumn('customers', $col)) {
                $drops[] = $col;
            }
        }

        if ($drops !== []) {
            Schema::table('customers', function (Blueprint $table) use ($drops) {
                $table->dropColumn($drops);
            });
        }
    }
};
