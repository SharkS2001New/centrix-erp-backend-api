<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organizations') && ! Schema::hasColumn('organizations', 'is_active')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('module_settings');
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('must_change_password')->default(false)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('organizations') && Schema::hasColumn('organizations', 'is_active')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('must_change_password');
            });
        }
    }
};
