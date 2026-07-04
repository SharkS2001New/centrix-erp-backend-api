<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('uoms')) {
            return;
        }

        Schema::table('uoms', function (Blueprint $table) {
            if (! Schema::hasColumn('uoms', 'uses_small_packaging')) {
                $table->boolean('uses_small_packaging')->default(true)->after('middle_factor');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('uoms')) {
            return;
        }

        Schema::table('uoms', function (Blueprint $table) {
            if (Schema::hasColumn('uoms', 'uses_small_packaging')) {
                $table->dropColumn('uses_small_packaging');
            }
        });
    }
};
