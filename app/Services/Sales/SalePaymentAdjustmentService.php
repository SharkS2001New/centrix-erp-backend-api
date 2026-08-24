<?php

namespace App\Services\Sales;

use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePaymentAdjustment;
use App\Services\Organization\OrganizationReferenceDataService;
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

                foreach ($this->expandCompositePaymentMethodRows($methodCode, $amount) as $part) {
                    $method = $this->resolvePaymentMethod($orgId, $part['method_code']);
                    if (! $method) {
                        throw new InvalidArgumentException("Payment method {$part['method_code']} is not configured.");
                    }

                    $created[] = SalePaymentAdjustment::create([
                        'sale_id' => $sale->id,
                        'payment_method_id' => $method->id,
                        'amount' => $part['amount'],
                        'adjustment_type' => $type,
                        'reference_number' => isset($row['reference_number'])
                            ? trim((string) $row['reference_number']) ?: null
                            : null,
                        'float_session_id' => $floatSessionId,
                        'paid_at' => $paidAt ?? now(),
                    ]);
                }
            }
        });

        return $created;
    }

    /**
     * Cashiers type CM for Cash+M-Pesa mixed tender — expand before catalog lookup.
     *
     * @return list<array{method_code: string, amount: float}>
     */
    protected function expandCompositePaymentMethodRows(string $methodCode, float $amount): array
    {
        $normalized = strtoupper(str_replace([' ', '-', '_', '/', '+', '.'], '', trim($methodCode)));
        $codes = [$normalized !== '' ? $normalized : 'CASH'];

        $singleAliases = [
            'C' => 'CASH',
            'CASH' => 'CASH',
            'M' => 'MPESA',
            'MPESA' => 'MPESA',
            'E' => 'EQUITY',
            'EQUITY' => 'EQUITY',
            'K' => 'KCB',
            'KCB' => 'KCB',
            'ECO' => 'ECOBANK',
            'ECOBANK' => 'ECOBANK',
            'CARD' => 'CARD',
            'BANK' => 'BANK',
        ];
        if (isset($singleAliases[$normalized])) {
            $codes = [$singleAliases[$normalized]];
        } elseif (preg_match('/^[CMEK]{2,4}$/', $normalized) === 1) {
            $letterMap = [
                'C' => 'CASH',
                'M' => 'MPESA',
                'E' => 'EQUITY',
                'K' => 'KCB',
            ];
            $expanded = [];
            foreach (str_split($normalized) as $ch) {
                $code = $letterMap[$ch] ?? null;
                if ($code !== null && ! in_array($code, $expanded, true)) {
                    $expanded[] = $code;
                }
            }
            if (count($expanded) >= 2) {
                $codes = $expanded;
            }
        }

        if (count($codes) <= 1) {
            return [[
                'method_code' => $codes[0],
                'amount' => round($amount, 2),
            ]];
        }

        $n = count($codes);
        $parts = [];
        $allocated = 0.0;
        foreach ($codes as $index => $code) {
            if ($index === $n - 1) {
                $share = round($amount - $allocated, 2);
            } else {
                $share = round($amount / $n, 2);
                $allocated = round($allocated + $share, 2);
            }
            if ($share > 0.009) {
                $parts[] = ['method_code' => $code, 'amount' => $share];
            }
        }

        return $parts !== [] ? $parts : [[
            'method_code' => $codes[0],
            'amount' => round($amount, 2),
        ]];
    }

    protected function resolvePaymentMethod(int $organizationId, string $methodCode): ?PaymentMethod
    {
        return app(OrganizationReferenceDataService::class)
            ->resolvePaymentMethod($organizationId, $methodCode);
    }
}
