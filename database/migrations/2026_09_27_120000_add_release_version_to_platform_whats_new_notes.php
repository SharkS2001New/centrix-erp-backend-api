<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_whats_new_notes')) {
            return;
        }
        if (! Schema::hasColumn('platform_whats_new_notes', 'release_version')) {
            Schema::table('platform_whats_new_notes', function (Blueprint $table) {
                $table->string('release_version', 32)->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_whats_new_notes') && Schema::hasColumn('platform_whats_new_notes', 'release_version')) {
            Schema::table('platform_whats_new_notes', function (Blueprint $table) {
                $table->dropColumn('release_version');
            });
        }
    }
};
