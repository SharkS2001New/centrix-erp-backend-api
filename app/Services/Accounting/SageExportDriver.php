<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccountMapping;
use App\Models\AccountingConnection;
use App\Models\AccountingExportQueue;
use App\Services\Accounting\Contracts\ExternalAccountingExportDriver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SageExportDriver implements ExternalAccountingExportDriver
{
    public function exportJournal(AccountingExportQueue $item): string
    {
        $connection = AccountingConnection::query()
            ->where('organization_id', $item->organization_id)
            ->where('provider', 'sage')
            ->where('status', 'connected')
            ->first();

        if (! $connection) {
            throw new \RuntimeException('Sage is not connected for this organization.');
        }

        $mappedLines = $this->applyMappings((int) $item->organization_id, $item->lines ?? []);
        if ($mappedLines === []) {
            throw new \RuntimeException('No mappable journal lines for export. Map chart of accounts to Sage first.');
        }

        if (! config('sage.client_id') || ! config('sage.client_secret')) {
            Log::info('Sage export stub', [
                'organization_id' => $item->organization_id,
                'entry_number' => $item->entry_number,
                'lines' => $mappedLines,
            ]);

            return 'SAGE-STUB-'.Str::upper(Str::random(8));
        }

        throw new \RuntimeException('Sage live export is not implemented yet. Configure stub mode or complete the API driver.');
    }

    /** @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function applyMappings(int $orgId, array $lines): array
    {
        $mappings = AccountingAccountMapping::query()
            ->where('organization_id', $orgId)
            ->where('provider', 'sage')
            ->get()
            ->keyBy('local_account_code');

        $mapped = [];
        foreach ($lines as $line) {
            $code = (string) ($line['account_code'] ?? '');
            $mapping = $mappings->get($code);
            if (! $mapping) {
                continue;
            }
            $mapped[] = array_merge($line, [
                'external_account_id' => $mapping->external_account_id,
                'external_account_name' => $mapping->external_account_name,
            ]);
        }

        return $mapped;
    }
}
