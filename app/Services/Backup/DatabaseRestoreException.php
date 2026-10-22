<?php

namespace App\Services\Backup;

class DatabaseRestoreException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $codeKey = 'restore_failed',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
