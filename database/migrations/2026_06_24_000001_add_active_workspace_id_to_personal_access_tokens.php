<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        if (! Schema::hasColumn('personal_access_tokens', 'active_workspace_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('active_workspace_id', 32)->nullable()->after('login_channel');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        if (Schema::hasColumn('personal_access_tokens', 'active_workspace_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('active_workspace_id');
            });
        }
    }
};
