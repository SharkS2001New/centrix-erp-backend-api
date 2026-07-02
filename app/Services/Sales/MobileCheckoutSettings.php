<?php

namespace App\Services\Sales;

use InvalidArgumentException;

class MobileCheckoutSettings
{
    public const MODE_SAVE_ONLY = 'save_only';

    public const MODE_PAYMENT = 'payment';

    public const MODE_ASK = 'ask';

    /** @return list<string> */
    public static function modes(): array
    {
        return [
            self::MODE_SAVE_ONLY,
            self::MODE_PAYMENT,
            self::MODE_ASK,
        ];
    }

    /** @param  array<string, mixed>  $salesSettings */
    public function mode(array $salesSettings): string
    {
        $mode = (string) ($salesSettings['mobile_checkout_mode'] ?? self::MODE_SAVE_ONLY);

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_SAVE_ONLY;
    }

    /**
     * Apply organization mobile checkout policy to checkout input.
     *
     * @param  array<string, mixed>  $salesSettings
     * @param  array<string, mixed>  $input
     */
    public function applyCheckoutPolicy(array $salesSettings, array &$input, string $channel): void
    {
        if ($channel !== 'mobile') {
            return;
        }

        $mode = $this->mode($salesSettings);

        if ($mode === self::MODE_SAVE_ONLY) {
            $input['save_only'] = true;
            $input['pay_now'] = 0;
            $input['is_credit_sale'] = false;

            return;
        }

        if ($mode === self::MODE_PAYMENT && ! empty($input['save_only'])) {
            throw new InvalidArgumentException(
                'This organization requires payment when completing orders on the mobile app.',
            );
        }

        if ($mode === self::MODE_PAYMENT) {
            unset($input['save_only']);
        }
    }

    /**
     * Resolve whether mobile checkout without payment should become save-only.
     *
     * @param  array<string, mixed>  $salesSettings
     */
    public function shouldDefaultMobileSaveOnly(
        array $salesSettings,
        string $channel,
        bool $saveOnlyRequested,
    ): bool {
        if ($channel !== 'mobile') {
            return false;
        }

        $mode = $this->mode($salesSettings);

        if ($mode === self::MODE_SAVE_ONLY || $saveOnlyRequested) {
            return true;
        }

        if ($mode === self::MODE_ASK) {
            return $saveOnlyRequested;
        }

        return false;
    }

    /** @param  array<string, mixed>  $salesSettings */
    public function requiresPaymentAtCheckout(array $salesSettings, string $channel): bool
    {
        return $channel === 'mobile'
            && $this->mode($salesSettings) === self::MODE_PAYMENT;
    }
}
