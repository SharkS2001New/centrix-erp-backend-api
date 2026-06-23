<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_entries', function (Blueprint $table) {
            $table->integer('organization_id')->nullable()->change();
        });

        Schema::table('ai_knowledge_entries', function (Blueprint $table) {
            $table->index(['workspace_id', 'confirmed'], 'ai_knowledge_platform_workspace_confirmed');
        });

        // Promote existing training notes to platform-wide knowledge.
        DB::table('ai_knowledge_entries')
            ->where('confirmed', true)
            ->update(['organization_id' => null]);
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_entries', function (Blueprint $table) {
            $table->dropIndex('ai_knowledge_platform_workspace_confirmed');
        });

        Schema::table('ai_knowledge_entries', function (Blueprint $table) {
            $table->integer('organization_id')->nullable(false)->change();
        });
    }
};
