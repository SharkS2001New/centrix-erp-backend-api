<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackupService;
use Illuminate\Console\Command;

class DatabaseBackupCommand extends Command
{
    protected $signature = 'erp:database-backup
                            {--no-email : Skip sending the backup notification email}
                            {--no-prune : Skip deleting backups older than retention days}';

    protected $description = 'Create a compressed database backup and optionally email it';

    public function handle(DatabaseBackupService $backups): int
    {
        if (! config('backup.enabled', true)) {
            $this->warn('Database backups are disabled (BACKUP_ENABLED=false).');

            return self::SUCCESS;
        }

        try {
            $backup = $backups->createBackup();
        } catch (\Throwable $e) {
            $this->error('Database backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup created: %s (%s)',
            $backup['relative_path'],
            $this->formatBytes($backup['size_bytes']),
        ));

        if (! $this->option('no-prune')) {
            $deleted = $backups->pruneOldBackups();
            if ($deleted > 0) {
                $this->line("Pruned {$deleted} old backup(s).");
            }
        }

        if (! $this->option('no-email')) {
            $sent = $backups->notifyByEmail($backup);

            if ($sent) {
                $this->line('Backup notification email sent.');
            } elseif (trim((string) config('backup.notify_email', '')) === '') {
                $this->comment('Set BACKUP_NOTIFY_EMAIL to receive backup notifications.');
            } else {
                $this->warn('Backup notification email could not be sent. Check MAIL_* settings and logs.');
            }
        }

        return self::SUCCESS;
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
