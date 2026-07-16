<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalize branch_id onto HR child tables for list filtering.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'employee_allowances',
        'employee_overtime',
        'employee_leave_days',
        'employee_cash_advances',
        'employee_deductions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->addBranchId($table);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if ($this->foreignKeyExists($table, $table.'_branch_id_foreign')) {
                    $blueprint->dropForeign(['branch_id']);
                }
                $blueprint->dropColumn('branch_id');
            });
        }
    }

    protected function addBranchId(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'branch_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (Schema::hasColumn($table, 'organization_id')) {
                $blueprint->integer('branch_id')->nullable()->after('organization_id');
            } else {
                $blueprint->integer('branch_id')->nullable()->after('employee_id');
            }
            $blueprint->index('branch_id');
        });

        if (Schema::hasColumn($table, 'employee_id')) {
            DB::statement("
                UPDATE {$table} t
                INNER JOIN employees e ON e.id = t.employee_id
                SET t.branch_id = e.branch_id
                WHERE t.branch_id IS NULL
            ");
        }

        if (! $this->foreignKeyExists($table, $table.'_branch_id_foreign')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            });
        }
    }

    protected function foreignKeyExists(string $table, string $constraint): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.table_constraints
             WHERE constraint_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ?',
            [$database, $table, $constraint, 'FOREIGN KEY'],
        );

        return (int) ($row->c ?? 0) > 0;
    }
};
