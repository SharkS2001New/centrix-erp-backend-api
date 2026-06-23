<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ai_knowledge_entries', 'workspace_id')) {
            Schema::table('ai_knowledge_entries', function (Blueprint $table) {
                $table->string('workspace_id', 32)->nullable()->after('path');
            });
        }

        if ($this->indexExists('ai_knowledge_entries', 'ai_knowledge_org_workspace_confirmed')) {
            Schema::table('ai_knowledge_entries', function (Blueprint $table) {
                $table->dropIndex('ai_knowledge_org_workspace_confirmed');
            });
        }

        Schema::table('ai_knowledge_entries', function (Blueprint $table) {
            $table->integer('organization_id')->nullable()->change();
        });

        if (! $this->indexExists('ai_knowledge_entries', 'ai_knowledge_platform_workspace_confirmed')) {
            Schema::table('ai_knowledge_entries', function (Blueprint $table) {
                $table->index(['workspace_id', 'confirmed'], 'ai_knowledge_platform_workspace_confirmed');
            });
        }

        // Promote existing training notes to platform-wide knowledge.
        DB::table('ai_knowledge_entries')
            ->where('confirmed', true)
            ->update(['organization_id' => null]);
    }

    public function down(): void
    {
        if ($this->indexExists('ai_knowledge_entries', 'ai_knowledge_platform_workspace_confirmed')) {
            Schema::table('ai_knowledge_entries', function (Blueprint $table) {
                $table->dropIndex('ai_knowledge_platform_workspace_confirmed');
            });
        }

        Schema::table('ai_knowledge_entries', function (Blueprint $table) {
            $table->integer('organization_id')->nullable(false)->change();
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT COUNT(*) AS count FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $index],
        );

        return (int) ($result[0]->count ?? 0) > 0;
    }
};
