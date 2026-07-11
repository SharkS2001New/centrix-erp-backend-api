<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('platform_contracts', 'interval')) {
                $table->string('interval', 20)->default('monthly')->after('currency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('platform_contracts', function (Blueprint $table) {
            if (Schema::hasColumn('platform_contracts', 'interval')) {
                $table->dropColumn('interval');
            }
        });
    }
};
