<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\CentrixSalesScope;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

/**
 * Covers the 2026-11-07 CentrixSalesScope indexed-column migration oracle
 * findings:
 *   1. PHP CentrixSalesScope::isLegacyImportFlag() and the MySQL generated
 *      column `sales.legacy_materialized` must agree on every input class.
 *      Prior to the fix, the three service-site `(bool)` casts accepted int
 *      2 / string "TRUE" / arrays as truthy while the SQL CASE expression
 *      treated all of those as 0 — a real PHP/SQL divergence that would
 *      produce inconsistent exclude-vs-include behavior.
 *   2. excludeLegacyMaterialized / legacyExcludeSql / scopeLegacyMaterialized
 *      all route through the indexed `legacy_materialized` column.
 */
class SalesLegacyMaterializedScopeTest extends TestCase
{
    use RefreshesErpDatabase;

    /** @dataProvider legacyFlagInputs */
    public function test_is_legacy_import_flag_matches_contract(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, CentrixSalesScope::isLegacyImportFlag($value));
    }

    public static function legacyFlagInputs(): array
    {
        return [
            'php true is legacy' => [true,     true],
            'int 1 is legacy' => [1,        true],
            'string "1" is legacy' => ['1',      true],
            'string "true" is legacy' => ['true',   true],
            'php false is live' => [false,    false],
            'int 0 is live' => [0,        false],
            'string "0" is live' => ['0',      false],
            'string "false" is live' => ['false',  false],
            'null is live' => [null,     false],
            'int 2 is live (contract-strict)' => [2,      false],
            'string "2" is live' => ['2',      false],
            'uppercase "TRUE" is live' => ['TRUE',   false],
            'empty string is live' => ['',       false],
            'array is live' => [[1],      false],
            'object is live' => [new \stdClass, false],
        ];
    }

    public function test_mysql_generated_column_matches_php_helper(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $branch = Branch::where('organization_id', $org->id)->firstOrFail();
        $cashier = User::where('organization_id', $org->id)->firstOrFail();

        $cases = [
            'php_true' => true,
            'int_1' => 1,
            'string_1' => '1',
            'string_true' => 'true',
            'php_false' => false,
            'int_0' => 0,
            'string_0' => '0',
            'string_false' => 'false',
            'php_null' => null,
            'int_2' => 2,
            'string_2' => '2',
            'uppercase_TRUE' => 'TRUE',
            'empty_string' => '',
            'array_truthy' => [1],
            'object_value' => new \stdClass,
            'absent_key' => '__ABSENT__',
        ];

        // Deterministic unique order_num per case to avoid uq_org_order_num
        // collisions — the table has a UNIQUE (organization_id, order_num)
        // index, and prior iterations of this test used random_int() which
        // could theoretically collide on flaky reruns.
        $seedOrderNum = 900_000;
        $i = 0;
        foreach ($cases as $label => $value) {
            $meta = $value === '__ABSENT__' ? ['something_else' => 1] : ['legacy_import' => $value];

            $sale = Sale::create([
                'order_num' => $seedOrderNum + $i++,
                'branch_id' => $branch->id,
                'organization_id' => $org->id,
                'cashier_id' => $cashier->id,
                'status' => 'completed',
                'order_total' => 100,
                'fulfillment_meta' => $meta,
            ]);

            $storedFlag = (int) DB::table('sales')
                ->where('id', $sale->id)
                ->value('legacy_materialized');

            $phpFlag = CentrixSalesScope::isLegacyImportFlag(
                $value === '__ABSENT__' ? null : $value
            );

            $this->assertSame(
                $phpFlag ? 1 : 0,
                $storedFlag,
                "MySQL ↔ PHP disagree for case [{$label}]: php=".($phpFlag ? 'true' : 'false').", sql={$storedFlag}",
            );
        }
    }

    public function test_composite_index_exists_with_expected_column_order(): void
    {
        // Oracle-suggested regression check: assert the actual index definition
        // on `sales` matches what the migration intended. More deterministic
        // than asserting planner choice via EXPLAIN (cardinality-sensitive).
        $columns = DB::select(
            "SELECT COLUMN_NAME, SEQ_IN_INDEX
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'sales'
               AND index_name = 'idx_sales_legacy_pipeline'
             ORDER BY SEQ_IN_INDEX"
        );

        $actual = array_map(fn ($row) => $row->COLUMN_NAME, $columns);
        $expected = ['organization_id', 'legacy_materialized', 'status', 'archived', 'effective_sale_date'];

        $this->assertSame($expected, $actual);
    }

    public function test_exclude_legacy_materialized_hits_indexed_column(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();

        $q = Sale::query()->where('organization_id', $org->id);
        CentrixSalesScope::excludeLegacyMaterialized($q);

        $sql = $q->toSql();
        $this->assertStringContainsString('legacy_materialized', $sql);
        $this->assertStringNotContainsString('JSON_EXTRACT', $sql);
    }

    public function test_legacy_exclude_sql_emits_indexed_predicate(): void
    {
        $this->assertSame('s.legacy_materialized = 0', CentrixSalesScope::legacyExcludeSql('s'));
        $this->assertSame('custom.legacy_materialized = 0', CentrixSalesScope::legacyExcludeSql('custom'));
    }

    public function test_scope_legacy_materialized_returns_only_legacy_rows(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $branch = Branch::where('organization_id', $org->id)->firstOrFail();
        $cashier = User::where('organization_id', $org->id)->firstOrFail();

        $legacy = Sale::create([
            'order_num' => 950_001,
            'branch_id' => $branch->id,
            'organization_id' => $org->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
            'order_total' => 100,
            'fulfillment_meta' => ['legacy_import' => true],
        ]);

        $live = Sale::create([
            'order_num' => 950_002,
            'branch_id' => $branch->id,
            'organization_id' => $org->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
            'order_total' => 100,
            'fulfillment_meta' => ['legacy_import' => false],
        ]);

        $ids = Sale::query()
            ->where('organization_id', $org->id)
            ->legacyMaterialized()
            ->pluck('id')
            ->all();

        $this->assertContains($legacy->id, $ids);
        $this->assertNotContains($live->id, $ids);
    }
}
