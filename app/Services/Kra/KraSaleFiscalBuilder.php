<?php

namespace App\Services\Kra;

use App\Models\Sale;
use App\Models\SaleItem;

/**
 * Build KRA/eTIMS workflow line items so transmitted totals match Centrix payable maths.
 *
 * sale_items.amount is pre order_discount; sales.order_total is post discount.
 * Comstore PLU lines use DiscAmt=0, so the discount must be allocated into SaleAmount.
 */
class KraSaleFiscalBuilder
{
    /**
     * @return list<array{
     *   product_name: string,
     *   product_code: string,
     *   quantity: float,
     *   amount: float,
     *   product_vat: float,
     *   vat_rate: float
     * }>
     */
    public static function orderItemsFromSale(Sale $sale): array
    {
        $sale->loadMissing(['items.product.vat']);
        $lines = $sale->items;
        if ($lines->isEmpty()) {
            return [];
        }

        $raw = [];
        $lineGross = 0.0;
        foreach ($lines as $line) {
            /** @var SaleItem $line */
            $amount = round(max(0.0, (float) $line->amount), 2);
            $vatRate = SalesVatCalculator::vatRateFromProduct($line->product);
            $productVat = round(max(0.0, (float) ($line->product_vat ?? 0)), 2);
            if ($vatRate > 0 && $amount > 0 && $productVat <= 0) {
                $productVat = SalesVatCalculator::vatFromInclusiveGross($amount, $vatRate);
            }
            $raw[] = [
                'product_name' => $line->resolvedProductName(),
                'product_code' => (string) $line->product_code,
                'quantity' => (float) $line->quantity,
                'amount' => $amount,
                'product_vat' => $productVat,
                'vat_rate' => $vatRate,
            ];
            $lineGross = round($lineGross + $amount, 2);
        }

        $orderDiscount = round(max(0.0, (float) ($sale->order_discount ?? 0)), 2);
        $targetTotal = round(max(0.0, (float) ($sale->order_total ?? ($lineGross - $orderDiscount))), 2);

        return self::allocateToTargetTotal($raw, $targetTotal);
    }

    /**
     * Proportionally scale inclusive line amounts (and VAT) so Σ amount = $targetTotal.
     *
     * @param  list<array{product_name: string, product_code: string, quantity: float, amount: float, product_vat: float, vat_rate: float}>  $items
     * @return list<array{product_name: string, product_code: string, quantity: float, amount: float, product_vat: float, vat_rate: float}>
     */
    public static function allocateToTargetTotal(array $items, float $targetTotal): array
    {
        if ($items === []) {
            return [];
        }

        $targetTotal = round(max(0.0, $targetTotal), 2);
        $lineGross = round(array_sum(array_map(fn (array $i) => (float) $i['amount'], $items)), 2);

        if ($lineGross <= 0.0 || abs($lineGross - $targetTotal) < 0.005) {
            return array_map(function (array $item): array {
                $amount = round(max(0.0, (float) $item['amount']), 2);
                $vatRate = (float) ($item['vat_rate'] ?? 0);
                $productVat = $vatRate > 0
                    ? SalesVatCalculator::vatFromInclusiveGross($amount, $vatRate)
                    : round(max(0.0, (float) ($item['product_vat'] ?? 0)), 2);

                return [
                    'product_name' => (string) $item['product_name'],
                    'product_code' => (string) $item['product_code'],
                    'quantity' => (float) $item['quantity'],
                    'amount' => $amount,
                    'product_vat' => $productVat,
                    'vat_rate' => $vatRate,
                ];
            }, $items);
        }

        $ratio = $targetTotal / $lineGross;
        $allocated = [];
        $running = 0.0;
        $lastIndex = count($items) - 1;

        foreach ($items as $index => $item) {
            $amount = round(max(0.0, (float) $item['amount']), 2);
            if ($index === $lastIndex) {
                $nextAmount = round(max(0.0, $targetTotal - $running), 2);
            } else {
                $nextAmount = round($amount * $ratio, 2);
                $running = round($running + $nextAmount, 2);
            }

            $vatRate = (float) ($item['vat_rate'] ?? 0);
            $productVat = $vatRate > 0
                ? SalesVatCalculator::vatFromInclusiveGross($nextAmount, $vatRate)
                : 0.0;

            $allocated[] = [
                'product_name' => (string) $item['product_name'],
                'product_code' => (string) $item['product_code'],
                'quantity' => (float) $item['quantity'],
                'amount' => $nextAmount,
                'product_vat' => $productVat,
                'vat_rate' => $vatRate,
            ];
        }

        return $allocated;
    }

    /**
     * @return array{cash: float, check: float, card: float}
     */
    public static function tendersFromSale(Sale $sale): array
    {
        $sale->loadMissing('payments.paymentMethod');
        $tenders = ['cash' => 0.0, 'check' => 0.0, 'card' => 0.0];

        foreach ($sale->payments as $payment) {
            $code = strtoupper(trim((string) ($payment->paymentMethod?->method_code ?? '')));
            if (in_array($code, ['CHEQUE', 'CHECK'], true)) {
                $tenders['check'] += (float) $payment->amount;
            } elseif ($code === 'CASH') {
                $tenders['cash'] += (float) $payment->amount;
            } else {
                $tenders['card'] += (float) $payment->amount;
            }
        }

        if ($sale->payments->isEmpty()) {
            $tenders = [
                'cash' => (float) ($sale->cash ?? 0),
                'check' => 0.0,
                'card' => (float) ($sale->mpesa_amount ?? 0)
                    + (float) ($sale->equity_amount ?? 0)
                    + (float) ($sale->kcb_amount ?? 0),
            ];
        }

        return [
            'cash' => round((float) $tenders['cash'], 2),
            'check' => round((float) $tenders['check'], 2),
            'card' => round((float) $tenders['card'], 2),
        ];
    }

    /**
     * Gross / VAT transmitted in a stored Comstore workflow payload.
     *
     * @param  array<string, mixed>|null  $requestPayload
     * @return array{gross: float|null, vat: float|null}
     */
    public static function totalsFromRequestPayload(?array $requestPayload): array
    {
        if (! is_array($requestPayload)) {
            return ['gross' => null, 'vat' => null];
        }

        $sign = $requestPayload['sign_structure'] ?? null;
        if (! is_array($sign)) {
            return ['gross' => null, 'vat' => null];
        }

        $gross = round(
            (float) ($sign['CashAmt'] ?? 0)
            + (float) ($sign['CheckAmt'] ?? 0)
            + (float) ($sign['CardAmt'] ?? 0),
            2,
        );

        $vat = round(
            (float) ($sign['Vat B(16.00%) value'] ?? 0)
            + (float) ($sign['Vat E(8%) value'] ?? 0),
            2,
        );

        // Prefer PLU sum when present (authoritative device gross).
        $plu = $requestPayload['plu_data'] ?? null;
        if (is_array($plu) && $plu !== []) {
            $pluSum = 0.0;
            foreach ($plu as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $pluSum += (float) ($line['SaleAmount'] ?? 0);
            }
            $gross = round($pluSum, 2);
        }

        return ['gross' => $gross, 'vat' => $vat];
    }
}
