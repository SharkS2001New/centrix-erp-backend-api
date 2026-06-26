<?php

namespace App\Services\Background;

use App\Models\BackgroundTask;

/**
 * Build background task results for large row fetches without storing JSON blobs in MySQL.
 */
class ReportFetchResultBuilder
{
    public function __construct(
        protected ReportRowCache $cache,
    ) {}

    public static function forTask(BackgroundTask $task): self
    {
        return new self(ReportRowCache::forTask($task->id, (int) $task->organization_id));
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function appendRows(array $rows): void
    {
        $this->cache->appendRows($rows);
    }

    /**
     * @return array{rows?: list<array<string, mixed>>, row_count: int, truncated: bool, data_path?: string}
     */
    public function finalize(bool $truncated = false): array
    {
        $this->cache->close();
        $rowCount = $this->cache->rowCount();
        $inlineLimit = (int) config('background.result_inline_row_limit', 500);

        $result = [
            'row_count' => $rowCount,
            'truncated' => $truncated,
        ];

        if ($rowCount <= $inlineLimit) {
            $result['rows'] = $this->cache->readAll($inlineLimit);

            return $result;
        }

        $result['data_path'] = $this->cache->diskPath();

        return $result;
    }
}
