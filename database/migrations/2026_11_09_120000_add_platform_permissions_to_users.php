<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'platform_permissions')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // null = unrestricted platform access (legacy owner). JSON list = limited areas.
            $table->json('platform_permissions')->nullable()->after('is_super_admin');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'platform_permissions')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('platform_permissions');
        });
    }
};
