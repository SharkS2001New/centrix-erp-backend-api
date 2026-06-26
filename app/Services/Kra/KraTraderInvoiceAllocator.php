<?php

namespace App\Services\Kra;

use App\Models\CreditNote;
use App\Models\KraResponse;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Assigns monotonic numeric TraderSystemInvoiceNumber values for Comstore / complete-workflow.
 *
 * KRA error 314 ("receiptNo is error") is usually caused by duplicate, non-numeric,
 * or out-of-sequence trader invoice numbers relative to prior submissions on the device.
 */
class KraTraderInvoiceAllocator
{
    public function forSale(Sale $sale, array $financeSettings = []): string
    {
        $orgId = (int) $sale->organization_id;
        $start = max(0, (int) ($financeSettings['kra_trader_invoice_start'] ?? 0));
        $orderNum = max(0, (int) $sale->order_num);
        $maxUsed = $this->maxUsedTraderInvoice($orgId);

        $next = max($start, $orderNum, $maxUsed + 1);

        return $this->format($next);
    }

    public function forCreditNote(CreditNote $creditNote, array $financeSettings = []): string
    {
        $orgId = (int) $creditNote->organization_id;
        $start = max(0, (int) ($financeSettings['kra_trader_invoice_start'] ?? 0));
        $maxUsed = $this->maxUsedTraderInvoice($orgId);

        $next = max($start, $maxUsed + 1, (int) $creditNote->id);

        return $this->format($next);
    }

    public function extractFromKraResponse(KraResponse $row): ?string
    {
        $payload = $row->request_payload;
        if (! is_array($payload)) {
            return null;
        }

        $fromStructure = $payload['sign_structure']['TraderSystemInvoiceNumber'] ?? null;
        if (is_string($fromStructure) && $this->isValidFormat($fromStructure)) {
            return $fromStructure;
        }

        return null;
    }

    public function isValidFormat(string $value): bool
    {
        $trimmed = trim($value);

        return $trimmed !== ''
            && strlen($trimmed) <= 10
            && ctype_digit($trimmed)
            && (int) $trimmed > 0;
    }

    protected function maxUsedTraderInvoice(int $organizationId): int
    {
        $max = 0;

        $kraRows = KraResponse::query()
            ->whereHas('sale', fn ($q) => $q->where('organization_id', $organizationId))
            ->whereNotNull('request_payload')
            ->orderByDesc('id')
            ->limit(500)
            ->pluck('request_payload');

        foreach ($kraRows as $payload) {
            if (! is_array($payload)) {
                continue;
            }
            $candidate = $payload['sign_structure']['TraderSystemInvoiceNumber'] ?? null;
            $max = max($max, $this->parseNumeric($candidate));
        }

        $creditPayloads = CreditNote::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('kra_request_payload')
            ->orderByDesc('id')
            ->limit(200)
            ->pluck('kra_request_payload');

        foreach ($creditPayloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }
            $candidate = $payload['sign_structure']['TraderSystemInvoiceNumber'] ?? null;
            $max = max($max, $this->parseNumeric($candidate));
        }

        $legacyMax = (int) DB::table('sales')
            ->where('organization_id', $organizationId)
            ->where('order_num', '<', 1_000_000)
            ->max('order_num');

        return max($max, $legacyMax);
    }

    protected function parseNumeric(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return 0;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $digits !== '' ? (int) $digits : 0;
    }

    protected function format(int $number): string
    {
        $value = max(1, $number);

        if ($value > 9_999_999_999) {
            $value = (int) substr((string) $value, -10);
        }

        return (string) $value;
    }
}
