<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_entries', function (Blueprint $table) {
            $table->string('workspace_id', 32)->nullable()->after('path');
            $table->index(['organization_id', 'workspace_id', 'confirmed'], 'ai_knowledge_org_workspace_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_entries', function (Blueprint $table) {
            $table->dropIndex('ai_knowledge_org_workspace_confirmed');
            $table->dropColumn('workspace_id');
        });
    }
};
