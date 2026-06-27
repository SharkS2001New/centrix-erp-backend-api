<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loading_list_lines')) {
            return;
        }

        if (! Schema::hasColumn('loading_list_lines', 'on_wholesale_retail')) {
            Schema::table('loading_list_lines', function (Blueprint $table) {
                $table->unsignedTinyInteger('on_wholesale_retail')->default(0)->after('line_total');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('loading_list_lines') && Schema::hasColumn('loading_list_lines', 'on_wholesale_retail')) {
            Schema::table('loading_list_lines', function (Blueprint $table) {
                $table->dropColumn('on_wholesale_retail');
            });
        }
    }
};
