<?php

namespace App\Services\Kra;

use App\Models\CreditNote;
use App\Models\KraResponse;
use App\Models\Sale;

/**
 * Assigns numeric TraderSystemInvoiceNumber values for Comstore / complete-workflow.
 *
 * Uses the same algorithm as LightStores KRAService::generateKRAInvoiceNumber()
 * (unix timestamp + random, 10 digits) so receipts look like legacy Comstore sales.
 * When time() already fills 10 digits, the last 3 are randomized to reduce same-second
 * collisions when several POS terminals share one on-prem device.
 * Failed submissions reuse the same trader number on retry.
 */
class KraTraderInvoiceAllocator
{
    public function forSale(Sale $sale, array $financeSettings = []): string
    {
        if ($sale->exists) {
            $existing = $this->extractFromLatestSaleAttempt($sale);
            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->generateUniqueForOrganization((int) $sale->organization_id);
    }

    public function forCreditNote(CreditNote $creditNote, array $financeSettings = []): string
    {
        $existing = $this->extractFromCreditNote($creditNote);
        if ($existing !== null) {
            return $existing;
        }

        return $this->generateUniqueForOrganization((int) $creditNote->organization_id);
    }

    public function extractFromKraResponse(KraResponse $row): ?string
    {
        return $this->extractFromPayload($row->request_payload);
    }

    public function extractFromCreditNote(CreditNote $creditNote): ?string
    {
        return $this->extractFromPayload($creditNote->kra_request_payload);
    }

    public function isValidFormat(string $value): bool
    {
        $trimmed = trim($value);

        return $trimmed !== ''
            && strlen($trimmed) <= 10
            && ctype_digit($trimmed)
            && (int) $trimmed > 0;
    }

    protected function extractFromLatestSaleAttempt(Sale $sale): ?string
    {
        $row = KraResponse::query()
            ->where('sale_id', $sale->id)
            ->whereNotNull('request_payload')
            ->orderByDesc('id')
            ->first();

        if (! $row) {
            return null;
        }

        return $this->extractFromKraResponse($row);
    }

    /** @param  mixed  $payload */
    protected function extractFromPayload(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $fromStructure = $payload['sign_structure']['TraderSystemInvoiceNumber'] ?? null;
        if (is_string($fromStructure) && $this->isValidFormat($fromStructure)) {
            return $fromStructure;
        }

        return null;
    }

    protected function generateUniqueForOrganization(int $organizationId): string
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $candidate = $this->generateCandidate();
            if (! $this->traderNumberAlreadyUsed($organizationId, $candidate)) {
                return $candidate;
            }

            usleep(random_int(1500, 6000));
        }

        return $this->generateCandidate(extraEntropy: true);
    }

    protected function generateCandidate(bool $extraEntropy = false): string
    {
        $ts = (string) time();
        $rand = random_int($extraEntropy ? 1000 : 100, 999);

        // LightStores: substr(time() . rand(100, 999), 0, 10)
        if (strlen($ts) < 10) {
            $value = substr($ts . $rand, 0, 10);
        } else {
            $value = substr($ts, 0, 7) . str_pad((string) ($rand % 1000), 3, '0', STR_PAD_LEFT);
        }

        $value = ltrim($value, '0');

        return $value !== '' ? $value : '1';
    }

    protected function traderNumberAlreadyUsed(int $organizationId, string $traderNumber): bool
    {
        $kraRows = KraResponse::query()
            ->whereHas('sale', fn ($q) => $q->where('organization_id', $organizationId))
            ->whereNotNull('request_payload')
            ->orderByDesc('id')
            ->limit(300)
            ->pluck('request_payload');

        foreach ($kraRows as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $candidate = $payload['sign_structure']['TraderSystemInvoiceNumber'] ?? null;
            if (is_string($candidate) && $candidate === $traderNumber) {
                return true;
            }
        }

        $creditPayloads = CreditNote::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('kra_request_payload')
            ->orderByDesc('id')
            ->limit(100)
            ->pluck('kra_request_payload');

        foreach ($creditPayloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $candidate = $payload['sign_structure']['TraderSystemInvoiceNumber'] ?? null;
            if (is_string($candidate) && $candidate === $traderNumber) {
                return true;
            }
        }

        return false;
    }
}
