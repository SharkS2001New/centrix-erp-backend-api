<?php

namespace App\Http\Controllers\Api\V1\Operations\Concerns;

use App\Models\MpesaIncomingPayment;
use App\Models\MpesaPaymentSkip;
use App\Models\TemporaryCart;
use Illuminate\Support\Collection;

trait HandlesMpesaPayments
{
    protected function mpesaPhoneVariants(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $variants = [];

        if (str_starts_with($digits, '254') && strlen($digits) === 12) {
            $variants[] = '0' . substr($digits, 3);
            $variants[] = $digits;
            $variants[] = substr($digits, 3);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $variants[] = $digits;
            $variants[] = '254' . substr($digits, 1);
            $variants[] = substr($digits, 1);
        } elseif (str_starts_with($digits, '7') && strlen($digits) === 9) {
            $variants[] = '0' . $digits;
            $variants[] = '254' . $digits;
            $variants[] = $digits;
        } elseif ($digits !== '') {
            $variants[] = $digits;
        }

        return array_values(array_unique($variants));
    }

    protected function displayMpesaPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '254') && strlen($digits) === 12) {
            return '0' . substr($digits, 3);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return $digits;
        }

        if (str_starts_with($digits, '7') && strlen($digits) === 9) {
            return '0' . $digits;
        }

        return $phone;
    }

    protected function formatMpesaPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            throw new \InvalidArgumentException('Enter a valid M-Pesa phone number.');
        }

        if (str_starts_with($digits, '254')) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '254' . substr($digits, 1);
        }

        if (str_starts_with($digits, '7') && strlen($digits) === 9) {
            return '254' . $digits;
        }

        throw new \InvalidArgumentException('Use a Kenyan mobile number like 0712345678.');
    }

    protected function mpesaFailureMessage(?int $resultCode, ?string $resultDesc): string
    {
        return match ($resultCode) {
            1032 => 'Payment cancelled on the phone.',
            1037 => 'Payment request timed out. Ask the customer to try again.',
            1 => 'Insufficient balance on M-Pesa.',
            2001 => 'Wrong PIN entered.',
            default => $resultDesc ?: 'M-Pesa payment was not completed.',
        };
    }

    protected function recordIncomingMpesaPayment(
        string $transactionId,
        string $phone,
        int $amount,
        string $source = 'c2b',
        ?int $organizationId = null,
        ?int $stkRequestId = null,
    ): MpesaIncomingPayment {
        $displayPhone = $this->displayMpesaPhone($phone);

        return MpesaIncomingPayment::firstOrCreate(
            ['transaction_id' => $transactionId],
            [
                'organization_id' => $organizationId,
                'phone_number' => $displayPhone,
                'amount' => $amount,
                'source' => $source,
                'status' => 'available',
                'stk_request_id' => $stkRequestId,
                'received_at' => now(),
            ],
        );
    }

    protected function incomingPaymentsForCart(TemporaryCart $cart, string $phone, ?int $organizationId = null): Collection
    {
        $skippedIds = MpesaPaymentSkip::where('cart_id', $cart->id)->pluck('mpesa_incoming_payment_id');
        $variants = $this->mpesaPhoneVariants($phone);

        return MpesaIncomingPayment::query()
            ->where('status', 'available')
            ->whereIn('phone_number', $variants)
            ->where('received_at', '>=', now()->subDay())
            ->when($organizationId, fn ($q) => $q->where(fn ($inner) => $inner
                ->where('organization_id', $organizationId)
                ->orWhereNull('organization_id')))
            ->when($skippedIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $skippedIds))
            ->orderByDesc('received_at')
            ->limit(10)
            ->get();
    }

    protected function refreshCartMpesaTotals(TemporaryCart $cart): TemporaryCart
    {
        $payments = MpesaIncomingPayment::query()
            ->where('applied_cart_id', $cart->id)
            ->where('status', 'applied')
            ->orderBy('applied_at')
            ->get();

        $total = (float) $payments->sum('applied_amount');
        $codes = $payments->pluck('transaction_id')->filter()->values()->join(',');

        $cart->update([
            'mpesa_payment_amount' => $total,
            'mpesa_transaction_code' => $codes !== '' ? $codes : null,
            'mpesa_phone' => $payments->last()?->phone_number ?? $cart->mpesa_phone,
        ]);
        $cart->increment('update_no');

        return $cart->fresh('lines');
    }

    protected function releaseCartMpesaPayments(TemporaryCart $cart): void
    {
        MpesaIncomingPayment::query()
            ->where('applied_cart_id', $cart->id)
            ->where('status', 'applied')
            ->update([
                'status' => 'available',
                'applied_cart_id' => null,
                'applied_amount' => null,
                'applied_at' => null,
            ]);

        MpesaPaymentSkip::where('cart_id', $cart->id)->delete();
    }
}
