<?php

namespace App\Services\Backup;

class DatabaseBackupException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $codeKey = 'backup_failed',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
