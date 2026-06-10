<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\CartLine;
use App\Models\Customer;
use App\Models\LoyaltyCard;
use App\Models\TemporaryCart;
use App\Models\Voucher;
use Carbon\Carbon;
use InvalidArgumentException;

trait HandlesCartPayments
{
    protected function cartOrderTotal(TemporaryCart $cart): float
    {
        $lineQuery = CartLine::where('cart_id', $cart->id);
        $lineNet = (float) $lineQuery->sum('amount');
        $vat = (float) CartLine::where('cart_id', $cart->id)->sum('product_vat');
        $orderDiscount = max(0, (float) ($cart->order_discount ?? 0));

        return max(0, $lineNet - min($orderDiscount, $lineNet) + $vat);
    }

    protected function cartAmountDue(TemporaryCart $cart): float
    {
        $total = $this->cartOrderTotal($cart);
        $voucherPay = max(0, (float) ($cart->voucher_payment_amount ?? 0));
        $pointsPay = max(0, (float) ($cart->points_payment_amount ?? 0));
        $mpesaPay = max(0, (float) ($cart->mpesa_payment_amount ?? 0));

        return max(0, $total - $voucherPay - $pointsPay - $mpesaPay);
    }

    protected function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    protected function findPaymentVoucher(int $organizationId, string $code): Voucher
    {
        $voucher = Voucher::where('organization_id', $organizationId)
            ->where('voucher_code', strtoupper(trim($code)))
            ->first();

        if (! $voucher) {
            throw new InvalidArgumentException('Voucher not found.');
        }

        if ($voucher->voucher_kind !== 'payment') {
            throw new InvalidArgumentException('This voucher code is not a payment voucher.');
        }

        if (! $voucher->is_active) {
            throw new InvalidArgumentException('Voucher is inactive.');
        }

        if ($voucher->valid_from && Carbon::parse($voucher->valid_from)->isFuture()) {
            throw new InvalidArgumentException('Voucher is not yet valid.');
        }

        if ($voucher->valid_until && Carbon::parse($voucher->valid_until)->isPast()) {
            throw new InvalidArgumentException('Voucher has expired.');
        }

        if ($voucher->max_redemptions !== null && (int) $voucher->redemption_count >= (int) $voucher->max_redemptions) {
            throw new InvalidArgumentException('Voucher redemption limit reached.');
        }

        if ((float) $voucher->balance <= 0) {
            throw new InvalidArgumentException('Voucher has no remaining balance.');
        }

        return $voucher;
    }

    protected function findLoyaltyCardByPhone(int $organizationId, string $phone, bool $requireRedeemableBalance = true): LoyaltyCard
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '') {
            throw new InvalidArgumentException('Enter a mobile phone number.');
        }

        $card = LoyaltyCard::with('customer')
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($normalized) {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(phone_number, ' ', ''), '-', ''), '+', ''), '(', '') LIKE ?",
                    ['%' . $normalized . '%'],
                );
            })
            ->first();

        if (! $card) {
            throw new InvalidArgumentException('No loyalty card found for this phone number.');
        }

        if (! $card->is_active) {
            throw new InvalidArgumentException('Loyalty card is inactive.');
        }

        if ($requireRedeemableBalance && (float) $card->points_balance <= 0) {
            throw new InvalidArgumentException('This loyalty card has no points to redeem.');
        }

        return $card;
    }

    protected function pointsCashValue(array $salesSettings, float $points): float
    {
        $rate = max(0, (float) ($salesSettings['point_cash_value'] ?? 1));

        return round(max(0, $points) * $rate, 2);
    }

    /** KES order total required to earn one loyalty point. */
    protected function pointsEarnedFromOrder(array $salesSettings, float $orderTotal): float
    {
        if (empty($salesSettings['enable_redeemable_points'])) {
            return 0;
        }

        $kesPerPoint = max(0, (float) ($salesSettings['points_earn_per_kes'] ?? 1000));
        if ($kesPerPoint <= 0 || $orderTotal <= 0) {
            return 0;
        }

        return floor($orderTotal / $kesPerPoint);
    }

    protected function awardLoyaltyPointsForCompletedSale(
        int $organizationId,
        ?int $customerNum,
        array $salesSettings,
        float $orderTotal,
        ?int $preferredCardId = null,
    ): float {
        if (! $customerNum) {
            return 0;
        }

        $earned = $this->pointsEarnedFromOrder($salesSettings, $orderTotal);
        if ($earned <= 0) {
            return 0;
        }

        $query = LoyaltyCard::where('organization_id', $organizationId)
            ->where('customer_num', $customerNum)
            ->where('is_active', true);

        if ($preferredCardId) {
            $query->where('id', $preferredCardId);
        }

        $card = $query->lockForUpdate()->first();
        if (! $card) {
            return 0;
        }

        $card->update([
            'points_balance' => (float) $card->points_balance + $earned,
        ]);

        return $earned;
    }

    protected function clearCartPaymentOptions(TemporaryCart $cart): void
    {
        if (method_exists($this, 'releaseCartMpesaPayments')) {
            $this->releaseCartMpesaPayments($cart);
        }

        $cart->update([
            'payment_voucher_id' => null,
            'voucher_payment_amount' => 0,
            'loyalty_card_id' => null,
            'points_redeemed' => 0,
            'points_payment_amount' => 0,
            'mpesa_phone' => null,
            'mpesa_payment_amount' => 0,
            'mpesa_transaction_code' => null,
        ]);
    }

    protected function syncCustomerPhoneOnCard(LoyaltyCard $card): void
    {
        $customer = Customer::find($card->customer_num);
        if ($customer?->phone_number && $customer->phone_number !== $card->phone_number) {
            $card->update(['phone_number' => $customer->phone_number]);
        }
    }
}
