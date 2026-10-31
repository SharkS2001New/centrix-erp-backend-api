<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations') || Schema::hasColumn('organizations', 'registration_source')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('registration_source', 32)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('organizations') && Schema::hasColumn('organizations', 'registration_source')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('registration_source');
            });
        }
    }
};
