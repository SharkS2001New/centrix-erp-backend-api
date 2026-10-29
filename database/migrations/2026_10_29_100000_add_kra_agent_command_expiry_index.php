<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kra_agent_commands', function (Blueprint $table) {
            $table->index(
                ['kra_agent_id', 'status', 'expires_at'],
                'kra_agent_cmd_agent_status_expiry_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('kra_agent_commands', function (Blueprint $table) {
            $table->dropIndex('kra_agent_cmd_agent_status_expiry_idx');
        });
    }
};
