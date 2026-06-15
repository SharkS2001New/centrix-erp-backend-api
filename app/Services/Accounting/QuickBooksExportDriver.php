<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccountMapping;
use App\Models\AccountingConnection;
use App\Models\AccountingExportQueue;
use App\Services\Accounting\Contracts\ExternalAccountingExportDriver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class QuickBooksExportDriver implements ExternalAccountingExportDriver
{
    public function __construct(
        protected QuickBooksApiClient $api,
    ) {}

    public function exportJournal(AccountingExportQueue $item): string
    {
        $connection = AccountingConnection::query()
            ->where('organization_id', $item->organization_id)
            ->where('provider', 'quickbooks')
            ->where('status', 'connected')
            ->first();

        if (! $connection) {
            throw new \RuntimeException('QuickBooks is not connected for this organization.');
        }

        $mappedLines = $this->applyMappings((int) $item->organization_id, $item->lines ?? []);
        if ($mappedLines === []) {
            throw new \RuntimeException('No mappable journal lines for export. Map chart of accounts to QuickBooks first.');
        }

        $config = QuickBooksSettingsResolver::forOrganization((int) $item->organization_id);
        if (! $config['client_id'] || ! $config['client_secret']) {
            Log::info('QuickBooks export stub', [
                'organization_id' => $item->organization_id,
                'entry_number' => $item->entry_number,
                'lines' => $mappedLines,
            ]);

            return 'QBO-STUB-'.Str::upper(Str::random(8));
        }

        return $this->api->createJournalEntry(
            connection: $connection,
            mappedLines: $mappedLines,
            docNumber: (string) $item->entry_number,
            txnDate: (string) $item->entry_date,
            description: $item->description,
        );
    }

    /** @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function applyMappings(int $orgId, array $lines): array
    {
        $mappings = AccountingAccountMapping::query()
            ->where('organization_id', $orgId)
            ->where('provider', 'quickbooks')
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
