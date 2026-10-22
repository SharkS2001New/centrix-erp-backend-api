<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_marketing_blogs')) {
            return;
        }
        if (Schema::hasColumn('platform_marketing_blogs', 'read_time')) {
            return;
        }
        Schema::table('platform_marketing_blogs', function (Blueprint $table) {
            $table->unsignedSmallInteger('read_time')->nullable()->after('author_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_marketing_blogs') || ! Schema::hasColumn('platform_marketing_blogs', 'read_time')) {
            return;
        }
        Schema::table('platform_marketing_blogs', function (Blueprint $table) {
            $table->dropColumn('read_time');
        });
    }
};
