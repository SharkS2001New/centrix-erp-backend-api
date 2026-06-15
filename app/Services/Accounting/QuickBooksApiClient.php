<?php

namespace App\Services\Accounting;

use App\Models\AccountingConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class QuickBooksApiClient
{
    public function __construct(
        protected QuickBooksOAuthService $oauth,
    ) {}

    /** @return array<int, array{id: string, name: string, account_type: ?string}> */
    public function listAccounts(AccountingConnection $connection): array
    {
        $connection = $this->oauth->ensureFreshToken($connection);
        $realmId = (string) $connection->realm_id;
        if ($realmId === '') {
            throw new \RuntimeException('QuickBooks company (realm) ID is missing.');
        }

        $response = $this->request($connection, 'GET', "/v3/company/{$realmId}/query", [
            'query' => 'select Id, Name, AccountType, AccountSubType, AcctNum from Account where Active = true maxresults 1000',
        ]);

        $accounts = $response['QueryResponse']['Account'] ?? [];

        return collect(is_array($accounts) ? $accounts : [$accounts])
            ->filter(fn ($row) => is_array($row) && isset($row['Id']))
            ->map(fn (array $row) => [
                'id' => (string) $row['Id'],
                'name' => (string) ($row['Name'] ?? ''),
                'account_type' => isset($row['AccountType']) ? (string) $row['AccountType'] : null,
                'account_number' => isset($row['AcctNum']) ? (string) $row['AcctNum'] : null,
            ])
            ->values()
            ->all();
    }

    /** @param  array<int, array<string, mixed>>  $mappedLines */
    public function createJournalEntry(
        AccountingConnection $connection,
        array $mappedLines,
        string $docNumber,
        string $txnDate,
        ?string $description = null,
    ): string {
        $connection = $this->oauth->ensureFreshToken($connection);
        $realmId = (string) $connection->realm_id;
        if ($realmId === '') {
            throw new \RuntimeException('QuickBooks company (realm) ID is missing.');
        }

        $lines = [];
        foreach ($mappedLines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            $accountId = (string) ($line['external_account_id'] ?? '');
            if ($accountId === '') {
                continue;
            }

            if ($debit > 0) {
                $lines[] = $this->journalLine($debit, 'Debit', $accountId, $line['line_notes'] ?? null);
            }
            if ($credit > 0) {
                $lines[] = $this->journalLine($credit, 'Credit', $accountId, $line['line_notes'] ?? null);
            }
        }

        if (count($lines) < 2) {
            throw new \RuntimeException('QuickBooks journal requires at least two mapped lines.');
        }

        $payload = [
            'DocNumber' => mb_substr($docNumber, 0, 21),
            'TxnDate' => $txnDate,
            'PrivateNote' => $description ? mb_substr($description, 0, 4000) : null,
            'Line' => $lines,
        ];

        $response = $this->request($connection, 'POST', "/v3/company/{$realmId}/journalentry", [], $payload);
        $entry = $response['JournalEntry'] ?? null;
        $id = is_array($entry) ? ($entry['Id'] ?? null) : null;

        if (! $id) {
            throw new \RuntimeException('QuickBooks did not return a journal entry ID.');
        }

        return 'QBO-'.(string) $id;
    }

    /** @param  array<string, mixed>  $query */
    protected function request(
        AccountingConnection $connection,
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
    ): array {
        $config = QuickBooksSettingsResolver::forOrganization((int) $connection->organization_id);
        $url = rtrim($config['api_base_url'], '/').$path;
        $pending = Http::withToken((string) $connection->access_token)
            ->acceptJson()
            ->asJson();

        $response = match (strtoupper($method)) {
            'GET' => $pending->get($url, $query),
            'POST' => $pending->post($url.($query ? '?'.http_build_query($query) : ''), $body ?? []),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        if (! $response->successful()) {
            $detail = $response->json('Fault.Error.0.Message') ?? $response->body();
            throw new \RuntimeException('QuickBooks API error: '.$detail);
        }

        return $response->json() ?? [];
    }

    protected function journalLine(float $amount, string $postingType, string $accountId, ?string $description): array
    {
        $line = [
            'DetailType' => 'JournalEntryLineDetail',
            'Amount' => $amount,
            'JournalEntryLineDetail' => [
                'PostingType' => $postingType,
                'AccountRef' => ['value' => $accountId],
            ],
        ];

        if ($description) {
            $line['Description'] = mb_substr($description, 0, 4000);
        }

        return $line;
    }
}
