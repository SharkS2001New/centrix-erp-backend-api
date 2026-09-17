<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kra_responses')) {
            return;
        }

        Schema::table('kra_responses', function (Blueprint $table) {
            if (! $this->indexExists('kra_responses', 'idx_kra_responses_org_created')) {
                $table->index(['organization_id', 'created_at'], 'idx_kra_responses_org_created');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kra_responses')) {
            return;
        }

        Schema::table('kra_responses', function (Blueprint $table) {
            if ($this->indexExists('kra_responses', 'idx_kra_responses_org_created')) {
                $table->dropIndex('idx_kra_responses_org_created');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $meta) {
            if (($meta['name'] ?? '') === $index) {
                return true;
            }
        }

        return false;
    }
};
