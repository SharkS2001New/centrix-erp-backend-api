<?php

namespace Tests\Feature;

use App\Services\Backup\DatabaseRestoreException;
use App\Services\Backup\DatabaseRestoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseRestoreTest extends TestCase
{
    protected string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = storage_path('framework/testing-restore-'.uniqid('', true).'.sqlite');
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
        touch($this->databasePath);

        config([
            'database.default' => 'testing_restore',
            'database.connections.testing_restore' => [
                'driver' => 'sqlite',
                'database' => $this->databasePath,
                'prefix' => '',
            ],
        ]);

        Schema::connection('testing_restore')->create('restore_demo', function ($table) {
            $table->integer('id')->primary();
            $table->string('name');
        });

        DB::connection('testing_restore')->table('restore_demo')->insert([
            ['id' => 1, 'name' => 'keep-me'],
            ['id' => 2, 'name' => 'will-replace'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('testing_restore');
        if (is_file($this->databasePath)) {
            @unlink($this->databasePath);
        }
        parent::tearDown();
    }

    public function test_merge_inserts_missing_rows_without_wiping_existing(): void
    {
        $sql = <<<'SQL'
INSERT INTO `restore_demo` (`id`, `name`) VALUES (2, 'from-dump');
INSERT INTO `restore_demo` (`id`, `name`) VALUES (3, 'new-row');
SQL;
        $path = $this->writeSql($sql);

        $result = app(DatabaseRestoreService::class)->restoreFromAbsolutePath(
            $path,
            'merge',
            ['restore_demo'],
            false,
            false,
            'demo.sql',
        );

        @unlink($path);

        $this->assertGreaterThanOrEqual(1, $result['statements_executed']);
        $rows = DB::connection('testing_restore')->table('restore_demo')->orderBy('id')->get()->keyBy('id');
        $this->assertSame('keep-me', $rows[1]->name);
        $this->assertSame('will-replace', $rows[2]->name);
        $this->assertSame('new-row', $rows[3]->name);
    }

    public function test_replace_truncates_then_loads_selected_table(): void
    {
        $sql = <<<'SQL'
INSERT INTO `restore_demo` (`id`, `name`) VALUES (9, 'only-from-dump');
SQL;
        $path = $this->writeSql($sql);

        $result = app(DatabaseRestoreService::class)->restoreFromAbsolutePath(
            $path,
            'replace',
            ['restore_demo'],
            false,
            false,
            'demo.sql',
        );

        @unlink($path);

        $this->assertSame(['restore_demo'], $result['truncated']);
        $rows = DB::connection('testing_restore')->table('restore_demo')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(9, (int) $rows[0]->id);
        $this->assertSame('only-from-dump', $rows[0]->name);
    }

    public function test_restore_rejects_an_active_transaction_before_truncating(): void
    {
        $path = $this->writeSql("INSERT INTO `restore_demo` (`id`, `name`) VALUES (9, 'only-from-dump');");
        $connection = DB::connection('testing_restore');
        $connection->beginTransaction();

        try {
            try {
                app(DatabaseRestoreService::class)->restoreFromAbsolutePath(
                    $path,
                    'replace',
                    ['restore_demo'],
                    false,
                    false,
                    'demo.sql',
                );
                $this->fail('Restore should reject an active database transaction.');
            } catch (DatabaseRestoreException $exception) {
                $this->assertSame('restore_in_transaction', $exception->codeKey);
            }

            $this->assertSame(1, $connection->transactionLevel());
            $this->assertSame(
                ['keep-me', 'will-replace'],
                $connection->table('restore_demo')->orderBy('id')->pluck('name')->all(),
            );
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            @unlink($path);
        }
    }

    public function test_restore_ignores_dump_transaction_control_statements(): void
    {
        $sql = <<<'SQL'
SET SESSION AUTOCOMMIT=0;
START TRANSACTION;
INSERT INTO `restore_demo` (`id`, `name`) VALUES (9, 'COMMIT is part of this value');
COMMIT;
SET GLOBAL AUTOCOMMIT=1;
SQL;
        $path = $this->writeSql($sql);

        app(DatabaseRestoreService::class)->restoreFromAbsolutePath(
            $path,
            'merge',
            null,
            false,
            true,
            'demo.sql',
        );

        @unlink($path);
        $row = DB::connection('testing_restore')->table('restore_demo')->where('id', 9)->first();
        $this->assertSame('COMMIT is part of this value', $row->name);
        $this->assertSame(0, DB::connection('testing_restore')->transactionLevel());
    }

    public function test_full_replace_requires_confirm(): void
    {
        $path = $this->writeSql("INSERT INTO `restore_demo` (`id`, `name`) VALUES (1, 'x');");

        try {
            app(DatabaseRestoreService::class)->restoreFromAbsolutePath(
                $path,
                'replace',
                null,
                false,
                false,
                'demo.sql',
            );
            $this->fail('Expected DatabaseRestoreException');
        } catch (DatabaseRestoreException $e) {
            $this->assertSame('confirm_full_required', $e->codeKey);
        } finally {
            @unlink($path);
        }
    }

    public function test_extended_insert_is_chunked_into_smaller_statements(): void
    {
        $service = app(DatabaseRestoreService::class);
        $values = [];
        for ($i = 1; $i <= 600; $i++) {
            $values[] = "({$i},'row-{$i}')";
        }
        $sql = 'INSERT IGNORE INTO `restore_demo` (`id`, `name`) VALUES '.implode(',', $values);
        $chunks = $service->expandExecutableStatements($sql, 'sqlite');

        $this->assertGreaterThan(1, count($chunks));
        $this->assertLessThanOrEqual(
            (int) ceil(600 / DatabaseRestoreService::INSERT_CHUNK_ROWS),
            count($chunks),
        );
    }

    public function test_generated_columns_are_stripped_from_insert_column_list(): void
    {
        $service = app(DatabaseRestoreService::class);
        $sql = "INSERT IGNORE INTO `customer_invoices` (`id`,`invoice_total`,`amount_paid`,`balance_due`) VALUES (1,100,40,60),(2,50,10,40)";
        $out = $service->stripGeneratedColumnsFromInsert($sql, 'customer_invoices', [
            'customer_invoices' => ['balance_due'],
        ]);

        $this->assertStringNotContainsString('balance_due', $out);
        $this->assertStringContainsString('`invoice_total`', $out);
        $this->assertStringContainsString('(1,100,40)', $out);
        $this->assertStringContainsString('(2,50,10)', $out);
    }

    protected function writeSql(string $sql): string
    {
        $path = storage_path('framework/testing-restore-dump-'.uniqid('', true).'.sql');
        file_put_contents($path, $sql);

        return $path;
    }
}
