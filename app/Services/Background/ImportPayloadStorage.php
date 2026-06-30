<?php

namespace App\Services\Background;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportPayloadStorage
{
    /**
     * Keep large import row sets out of the background_tasks JSON column.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows?: array<int, array<string, mixed>>, rows_path?: string, row_count?: int}
     */
    public function payloadForRows(array $rows, int $inlineLimit = 200): array
    {
        if (count($rows) <= $inlineLimit) {
            return ['rows' => $rows];
        }

        $path = $this->storeRows((string) Str::uuid(), $rows);

        return [
            'rows_path' => $path,
            'row_count' => count($rows),
        ];
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    public function storeRows(string $key, array $rows): string
    {
        $path = 'imports/'.$key.'.json';
        Storage::disk('local')->put(
            $path,
            json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        return $path;
    }

    /** @return array<int, array<string, mixed>> */
    public function loadRows(?string $path): array
    {
        if (! is_string($path) || $path === '') {
            return [];
        }

        if (! Storage::disk('local')->exists($path)) {
            throw new \RuntimeException('Import row file is missing. Please upload the file again.');
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Import row file could not be read.');
        }

        return $decoded;
    }

    public function delete(?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        Storage::disk('local')->delete($path);
    }
}
