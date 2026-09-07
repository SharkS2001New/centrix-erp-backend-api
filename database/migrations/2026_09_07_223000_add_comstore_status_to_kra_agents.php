<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kra_agents')) {
            return;
        }

        Schema::table('kra_agents', function (Blueprint $table) {
            if (! Schema::hasColumn('kra_agents', 'comstore_reachable')) {
                $table->boolean('comstore_reachable')->nullable()->after('agent_version');
            }
            if (! Schema::hasColumn('kra_agents', 'comstore_status_message')) {
                $table->string('comstore_status_message', 500)->nullable()->after('comstore_reachable');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kra_agents')) {
            return;
        }

        Schema::table('kra_agents', function (Blueprint $table) {
            if (Schema::hasColumn('kra_agents', 'comstore_status_message')) {
                $table->dropColumn('comstore_status_message');
            }
            if (Schema::hasColumn('kra_agents', 'comstore_reachable')) {
                $table->dropColumn('comstore_reachable');
            }
        });
    }
};
