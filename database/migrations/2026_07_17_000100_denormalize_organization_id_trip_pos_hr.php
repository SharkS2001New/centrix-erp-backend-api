<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalize organization_id onto remaining trip/POS/HR operational tables.
 */
return new class extends Migration
{
    /** @var array<string, string> table => backfill strategy */
    private const TABLES = [
        'drivers' => 'branch',
        'vehicles' => 'branch',
        'route_schedules' => 'branch',
        'tills' => 'branch',
        'till_float_sessions' => 'branch',
        'stock_take_sessions' => 'branch',
        'payroll_runs' => 'pay_period',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $strategy) {
            $this->addOrganizationId($table, $strategy);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if ($this->foreignKeyExists($table, $table.'_organization_id_foreign')) {
                    $blueprint->dropForeign(['organization_id']);
                }
                $blueprint->dropColumn('organization_id');
            });
        }
    }

    protected function addOrganizationId(string $table, string $strategy): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'organization_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->integer('organization_id')->nullable()->after('id');
            $blueprint->index('organization_id');
        });

        if ($strategy === 'branch' && Schema::hasColumn($table, 'branch_id')) {
            DB::statement("
                UPDATE {$table} t
                INNER JOIN branches b ON b.id = t.branch_id
                SET t.organization_id = b.organization_id
                WHERE t.organization_id IS NULL
            ");
        }

        if ($strategy === 'pay_period' && Schema::hasTable('pay_periods')) {
            DB::statement('
                UPDATE payroll_runs pr
                INNER JOIN pay_periods pp ON pp.id = pr.pay_period_id
                SET pr.organization_id = pp.organization_id
                WHERE pr.organization_id IS NULL
            ');
        }

        $fallbackOrgId = (int) (DB::table('organizations')->orderBy('id')->value('id') ?? 0);
        if ($fallbackOrgId > 0) {
            DB::table($table)->whereNull('organization_id')->update(['organization_id' => $fallbackOrgId]);
        }

        DB::statement("ALTER TABLE {$table} MODIFY organization_id INT NOT NULL");

        if (! $this->foreignKeyExists($table, $table.'_organization_id_foreign')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('organization_id')->references('id')->on('organizations');
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
