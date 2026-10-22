<?php

namespace Tests\Unit;

use App\Services\Backup\DatabaseRestoreException;
use App\Services\Backup\RestoreDatasetCatalog;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RestoreDatasetCatalogTest extends TestCase
{
    public function test_datasets_include_hr_attendance_pack(): void
    {
        $datasets = app(RestoreDatasetCatalog::class)->datasets();
        $ids = array_column($datasets, 'id');

        $this->assertContains('hr_attendance', $ids);
        $this->assertContains('hr_attendance_with_punches', $ids);

        $hr = collect($datasets)->firstWhere('id', 'hr_attendance');
        $this->assertContains('employee_attendance', $hr['tables']);
        $this->assertContains('employee_clock_sessions', $hr['tables']);
    }

    public function test_resolve_tables_expands_dataset_to_existing_tables_only(): void
    {
        Schema::shouldReceive('hasTable')
            ->andReturnUsing(fn (string $table) => in_array($table, [
                'employee_attendance',
                'employee_clock_sessions',
            ], true));

        $tables = app(RestoreDatasetCatalog::class)->resolveTables('hr_attendance_with_punches');

        $this->assertSame(['employee_attendance', 'employee_clock_sessions'], $tables);
    }

    public function test_unknown_dataset_throws(): void
    {
        $this->expectException(DatabaseRestoreException::class);
        app(RestoreDatasetCatalog::class)->resolveTables('not_a_real_pack');
    }
}
