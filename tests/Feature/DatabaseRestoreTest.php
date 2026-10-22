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

    protected function writeSql(string $sql): string
    {
        $path = storage_path('framework/testing-restore-dump-'.uniqid('', true).'.sql');
        file_put_contents($path, $sql);

        return $path;
    }
}
