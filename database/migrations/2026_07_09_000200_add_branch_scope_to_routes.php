<?php

use App\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('routes')) {
            return;
        }

        if (! Schema::hasColumn('routes', 'branch_id')) {
            Schema::table('routes', function (Blueprint $table) {
                // branches.id is INT — must match for MySQL FK compatibility.
                $table->integer('branch_id')->nullable()->after('organization_id');
            });
        } else {
            DB::statement('ALTER TABLE routes MODIFY branch_id INT NULL');
        }

        if (! $this->indexExists('routes', 'idx_routes_org_branch')) {
            Schema::table('routes', function (Blueprint $table) {
                $table->index(['organization_id', 'branch_id'], 'idx_routes_org_branch');
            });
        }

        $this->backfillBranchScope();
        $this->addForeignKeyIfMissing();
    }

    public function down(): void
    {
        if (! Schema::hasTable('routes') || ! Schema::hasColumn('routes', 'branch_id')) {
            return;
        }

        Schema::table('routes', function (Blueprint $table) {
            try {
                $table->dropForeign('routes_branch_id_foreign');
            } catch (\Throwable $e) {
                // Ignore when foreign key is absent.
            }
            try {
                $table->dropIndex('idx_routes_org_branch');
            } catch (\Throwable $e) {
                // Ignore when index is absent.
            }
            $table->dropColumn('branch_id');
        });
    }

    protected function backfillBranchScope(): void
    {
        $organizationIds = DB::table('routes')
            ->whereNotNull('organization_id')
            ->distinct()
            ->pluck('organization_id');

        foreach ($organizationIds as $organizationId) {
            $organizationId = (int) $organizationId;
            $branchId = $this->headOfficeBranchId($organizationId);
            if (! $branchId) {
                continue;
            }

            DB::table('routes')
                ->where('organization_id', $organizationId)
                ->where(function ($query) use ($branchId) {
                    $query->whereNull('branch_id')
                        ->orWhere('branch_id', '<>', $branchId);
                })
                ->update(['branch_id' => $branchId]);
        }
    }

    protected function addForeignKeyIfMissing(): void
    {
        if ($this->foreignExists('routes', 'routes_branch_id_foreign')) {
            return;
        }

        Schema::table('routes', function (Blueprint $table) {
            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->nullOnDelete();
        });
    }

    protected function headOfficeBranchId(int $organizationId): ?int
    {
        $branch = Branch::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) {
                $query->where('branch_code', 'HQ')
                    ->orWhere('branch_name', 'like', '%Head Office%');
            })
            ->orderBy('id')
            ->first();

        if (! $branch) {
            $branch = Branch::query()
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->first();
        }

        return $branch ? (int) $branch->id : null;
    }

    protected function foreignExists(string $table, string $constraint): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c
             FROM information_schema.table_constraints
             WHERE table_schema = ?
               AND table_name = ?
               AND constraint_name = ?
               AND constraint_type = ?',
            [$database, $table, $constraint, 'FOREIGN KEY'],
        );

        return (int) ($row->c ?? 0) > 0;
    }

    protected function indexExists(string $table, string $index): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $index],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
