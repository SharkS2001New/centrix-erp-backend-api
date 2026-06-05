<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uoms', function (Blueprint $table) {
            $table->string('measure_name', 100)->nullable()->after('full_name');
        });
    }

    public function down(): void
    {
        Schema::table('uoms', function (Blueprint $table) {
            $table->dropColumn('measure_name');
        });
    }
};
