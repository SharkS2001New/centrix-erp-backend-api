<?php

namespace Tests\Feature;

use App\Services\Backup\DatabaseBackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    public function test_sqlite_backup_is_written_to_configured_disk(): void
    {
        $databasePath = storage_path('framework/testing-backup.sqlite');
        if (is_file($databasePath)) {
            unlink($databasePath);
        }

        touch($databasePath);

        config([
            'database.default' => 'testing_backup',
            'database.connections.testing_backup' => [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
            ],
            'backup.disk' => 'local',
            'backup.path' => 'backups/testing',
            'backup.compress' => true,
        ]);

        Storage::fake('local');

        $backup = app(DatabaseBackupService::class)->createBackup('testing_backup');

        $this->assertTrue(Storage::disk('local')->exists($backup['relative_path']));
        $this->assertStringEndsWith('.sql.gz', $backup['filename']);
        $this->assertGreaterThan(0, $backup['size_bytes']);

        @unlink($databasePath);
    }

    public function test_backup_command_prunes_old_files(): void
    {
        $directory = 'backups/testing-'.uniqid('', true);
        config([
            'backup.enabled' => true,
            'backup.disk' => 'local',
            'backup.path' => $directory,
            'backup.retention_days' => 7,
            'backup.notify_email' => null,
        ]);

        Storage::disk('local')->makeDirectory($directory);
        $oldPath = Storage::disk('local')->path($directory.'/old.sql.gz');
        file_put_contents($oldPath, 'old');
        touch($oldPath, now()->subDays(10)->getTimestamp());

        $deleted = app(DatabaseBackupService::class)->pruneOldBackups();

        $this->assertSame(1, $deleted);
        $this->assertFalse(Storage::disk('local')->exists($directory.'/old.sql.gz'));

        Storage::disk('local')->deleteDirectory($directory);
    }

    public function test_backup_email_is_sent_when_recipient_is_configured(): void
    {
        Mail::fake();

        config([
            'mail.default' => 'array',
            'mail.from.address' => 'backup@example.com',
            'mail.from.name' => 'Centrix ERP',
            'backup.notify_email' => 'ops@example.com',
            'backup.attach_max_bytes' => 1024 * 1024,
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'backup');
        file_put_contents($tempFile, 'dump');

        $sent = app(DatabaseBackupService::class)->notifyByEmail([
            'database' => 'pos_erp',
            'driver' => 'mysql',
            'filename' => 'pos_erp_2026-06-20.sql.gz',
            'size_bytes' => 12,
            'absolute_path' => $tempFile,
            'relative_path' => 'backups/testing/sample.sql.gz',
            'disk' => 'local',
            'compressed' => true,
        ]);

        @unlink($tempFile);

        $this->assertTrue($sent);
    }

    public function test_destructive_commands_are_blocked_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['database.allow_destructive_commands' => false]);

        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $exitCode = Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString(
            'prohibited',
            strtolower(Artisan::output()),
        );

        DB::prohibitDestructiveCommands(false);
    }
}
