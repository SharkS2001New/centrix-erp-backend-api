<?php

namespace App\Services\Sales;

use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePaymentAdjustment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalePaymentAdjustmentService
{
    /**
     * @param  list<array{method_code: string, amount: float|int|string, adjustment_type: string, reference_number?: string|null}>  $rows
     * @return list<SalePaymentAdjustment>
     */
    public function recordForSale(
        Sale $sale,
        array $rows,
        ?int $floatSessionId = null,
        mixed $paidAt = null,
    ): array {
        if ($rows === []) {
            return [];
        }

        $orgId = (int) ($sale->organization_id ?? 0);
        $created = [];

        DB::transaction(function () use ($rows, $sale, $orgId, $floatSessionId, $paidAt, &$created) {
            foreach ($rows as $row) {
                $type = strtolower(trim((string) ($row['adjustment_type'] ?? '')));
                if (! in_array($type, ['return', 'topup'], true)) {
                    throw new InvalidArgumentException('Invalid payment adjustment type.');
                }
                $amount = round((float) ($row['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }
                $methodCode = strtoupper(trim((string) ($row['method_code'] ?? '')));
                if ($methodCode === '') {
                    throw new InvalidArgumentException('Payment adjustment requires a method code.');
                }
                $method = $this->resolvePaymentMethod($orgId, $methodCode);
                if (! $method) {
                    throw new InvalidArgumentException("Payment method {$methodCode} is not configured.");
                }

                $created[] = SalePaymentAdjustment::create([
                    'sale_id' => $sale->id,
                    'payment_method_id' => $method->id,
                    'amount' => $amount,
                    'adjustment_type' => $type,
                    'reference_number' => isset($row['reference_number'])
                        ? trim((string) $row['reference_number']) ?: null
                        : null,
                    'float_session_id' => $floatSessionId,
                    'paid_at' => $paidAt ?? now(),
                ]);
            }
        });

        return $created;
    }

    protected function resolvePaymentMethod(int $organizationId, string $methodCode): ?PaymentMethod
    {
        $normalized = strtoupper(str_replace([' ', '-'], '_', $methodCode));
        $normalized = match ($normalized) {
            'M_PESA' => 'MPESA',
            'BANK_TRANSFER', 'TRANSFER' => 'BANK',
            default => $normalized,
        };

        return PaymentMethod::query()
            ->where('is_active', 1)
            ->where(function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id');
            })
            ->where(function ($q) use ($normalized) {
                $q->whereRaw('UPPER(REPLACE(REPLACE(method_code, " ", "_"), "-", "_")) = ?', [$normalized])
                    ->orWhere('method_code', $normalized);
            })
            ->orderByRaw('organization_id IS NULL ASC')
            ->first();
    }
}
