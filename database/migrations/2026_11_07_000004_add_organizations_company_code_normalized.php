<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index tenant lookup by normalized company_code.
 *
 * Organization::findByCompanyCodeIdentifier (app/Models/Organization.php:44)
 * resolves user-entered identifiers at every login, session resume, passkey
 * challenge, mobile attendance clock-in, M-Pesa paybill C2B callback, and
 * password reset. Prod EXPLAIN on 2026-11-07 showed the primary path as
 * a function-wrapped WHERE:
 *
 *   SELECT * FROM organizations
 *   WHERE UPPER(REPLACE(REPLACE(REPLACE(company_code, '-', ''), ' ', ''), '_', '')) = ?
 *     AND deleted_at IS NULL LIMIT 1;
 *
 * Function-wrapped predicates cannot use an index — scan grows linearly with
 * tenant count. At 15 rows today it is <2 ms; at 10k tenants it becomes a
 * per-request wall-clock cost on the login hot path.
 *
 * Add a STORED generated column that materializes the normalized form, plus a
 * UNIQUE index on it. MySQL 8.0+ REGEXP_REPLACE handles the normalization in
 * the server, matching Organization::normalizeCompanyCodeIdentifier()'s PHP
 * contract (strip everything non-alphanumeric, uppercase).
 *
 * STORED (not VIRTUAL) because:
 *   - A STORED column with a UNIQUE index is the canonical pattern in MySQL 8
 *     (no edge-case caveats from InnoDB like VIRTUAL has).
 *   - Storage cost is 45 bytes × row count — negligible at any realistic
 *     tenant scale.
 *
 * UNIQUE because normalized collisions (e.g. one tenant with `MOON-CO` and
 * another with `MOON_CO`) must be prevented at the DB level. The existing
 * OrganizationCompanyCodeService::assertCodeAvailable() does this check in
 * PHP; the UNIQUE index backs it up and catches races.
 *
 * Preflight on prod 2026-11-07: 15 existing rows, zero normalized collisions
 * under the new expression. Live rows (12) and soft-deleted rows (3 with the
 * __DELETED__N suffix convention) all produce distinct normalized values.
 *
 * Normalization semantics differ slightly from the existing SQL:
 *   - Old SQL: strips only '-', ' ', '_'. Does not match PHP for codes
 *     containing other punctuation.
 *   - New SQL: strips all non-alphanumeric characters. Exactly matches the
 *     PHP normalizer. For codes like `MOON.CO` that could previously only be
 *     resolved via the aliases fallback, the primary fast path now catches
 *     them too — strict improvement, no existing lookup regresses.
 *
 * Model-side query change lives in Organization.php; this migration is
 * safe to ship independently because the old whereRaw still works against
 * the base column (ignores the new index) until the model is updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        if (! Schema::hasColumn('organizations', 'company_code_normalized')) {
            // Raw DDL because Laravel's Blueprint fluent generated-column syntax
            // in 11.x/12.x lacks a clean STORED + UNIQUE form; the raw statement
            // is also idiomatic alongside migrations like
            // 2026_11_07_000001_add_sales_pending_stock_deduct_index.php.
            //
            // INVISIBLE so the column stays queryable by name
            // (`where('company_code_normalized', ...)`) but is hidden from
            // `SELECT *` and Eloquent hydration — otherwise it would leak into
            // JSON responses from any controller that serializes an Organization
            // model directly (e.g. OrganizationProvisionController).
            //
            // Single-quoted SQL literals (not double) so the expression still
            // works if ANSI_QUOTES is ever enabled (double quotes then mean
            // identifier).
            DB::statement(
                "ALTER TABLE organizations
                 ADD COLUMN company_code_normalized VARCHAR(45)
                     GENERATED ALWAYS AS (
                         UPPER(REGEXP_REPLACE(company_code, '[^A-Za-z0-9]', ''))
                     ) STORED INVISIBLE"
            );
        }

        if (! $this->indexExists('organizations', 'uq_organizations_company_code_normalized')) {
            DB::statement(
                'ALTER TABLE organizations
                 ADD UNIQUE KEY uq_organizations_company_code_normalized
                     (company_code_normalized)'
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        if ($this->indexExists('organizations', 'uq_organizations_company_code_normalized')) {
            DB::statement('ALTER TABLE organizations DROP INDEX uq_organizations_company_code_normalized');
        }

        if (Schema::hasColumn('organizations', 'company_code_normalized')) {
            DB::statement('ALTER TABLE organizations DROP COLUMN company_code_normalized');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        return (bool) $connection->selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$database, $table, $index],
        );
    }
};
