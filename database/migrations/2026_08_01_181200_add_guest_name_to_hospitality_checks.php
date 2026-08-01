<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitality_checks', function (Blueprint $table) {
            if (! Schema::hasColumn('hospitality_checks', 'guest_name')) {
                $table->string('guest_name', 160)->nullable()->after('service_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hospitality_checks', function (Blueprint $table) {
            if (Schema::hasColumn('hospitality_checks', 'guest_name')) {
                $table->dropColumn('guest_name');
            }
        });
    }
};
