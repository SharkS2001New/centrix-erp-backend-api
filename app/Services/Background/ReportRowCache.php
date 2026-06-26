<?php

namespace App\Services\Background;

use RuntimeException;

/**
 * Append-only JSONL cache for large background fetch results (avoids huge JSON in DB).
 */
class ReportRowCache
{
    /** @var resource|null */
    protected $handle = null;

    protected int $rowCount = 0;

    public function __construct(
        protected string $diskPath,
    ) {}

    public static function forTask(string $taskId, int $organizationId): self
    {
        $exportRoot = trim((string) config('background.export_directory', 'private/exports'), '/');
        $directory = $exportRoot.'/'.$organizationId.'/task-data';
        $fullDirectory = storage_path('app/'.$directory);
        if (! is_dir($fullDirectory)) {
            mkdir($fullDirectory, 0755, true);
        }

        return new self($directory.'/task-'.$taskId.'.jsonl');
    }

    public function diskPath(): string
    {
        return $this->diskPath;
    }

    public function absolutePath(): string
    {
        return storage_path('app/'.$this->diskPath);
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function appendRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $handle = $this->openHandle();
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                continue;
            }
            fwrite($handle, $encoded."\n");
            $this->rowCount++;
        }
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readAll(int $maxRows = 0): array
    {
        $absolute = $this->absolutePath();
        if (! is_file($absolute)) {
            return [];
        }

        $limit = $maxRows > 0 ? $maxRows : (int) config('background.max_fetch_rows', 50_000);
        $rows = [];
        $handle = fopen($absolute, 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not read cached report data.');
        }

        while (($line = fgets($handle)) !== false) {
            if (count($rows) >= $limit) {
                break;
            }
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @return resource */
    protected function openHandle()
    {
        if (is_resource($this->handle)) {
            return $this->handle;
        }

        $absolute = $this->absolutePath();
        $dir = dirname($absolute);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($absolute, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Could not open report row cache file.');
        }

        $this->handle = $handle;

        return $handle;
    }

    public function __destruct()
    {
        $this->close();
    }
}
