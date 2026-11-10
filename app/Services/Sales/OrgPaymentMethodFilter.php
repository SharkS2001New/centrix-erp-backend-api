<?php

namespace App\Services\Sales;

use App\Models\PaymentMethod;
use Illuminate\Support\Collection;

/**
 * Filter Administration → Payment methods catalog by Settings → Sales → Recording payments.
 *
 * Mirrors frontend {@see filterPaymentMethodsForOrg} so Centrix AS Mobile / checkout
 * only offer tenders the org admin enabled.
 */
class OrgPaymentMethodFilter
{
    /** @var list<string> */
    private const MPESA_CODES = ['MPESA', 'M-PESA', 'M_PESA'];

    /** @var list<string> */
    private const CHEQUE_CODES = ['CHEQUE', 'CHECK'];

    /** @var list<string> */
    private const OTHER_BANK_CODES = ['OTHER', 'BANK', 'BANK_TRANSFER', 'TRANSFER'];

    /** @var list<string> */
    private const PREFERRED_ORDER = [
        'CASH',
        'MPESA',
        'EQUITY',
        'KCB',
        'OTHER',
        'BANK',
        'CHEQUE',
        'CREDIT',
    ];

    /**
     * @param  array<string, mixed>  $sales  org module_settings.sales
     * @param  array{
     *   include_credit?: bool,
     *   mpesa_platform?: bool,
     *   equity_platform?: bool
     * }  $options
     * @return array{
     *   cash: bool,
     *   mpesa: bool,
     *   equity: bool,
     *   kcb: bool,
     *   other_bank: bool,
     *   cheque: bool,
     *   credit: bool
     * }
     */
    public static function flags(array $sales, array $options = []): array
    {
        $mpesaPlatform = array_key_exists('mpesa_platform', $options)
            ? (bool) $options['mpesa_platform']
            : true;
        $equityPlatform = array_key_exists('equity_platform', $options)
            ? (bool) $options['equity_platform']
            : true;

        return [
            'cash' => true,
            'mpesa' => $mpesaPlatform && (($sales['enable_mpesa_amount'] ?? true) !== false),
            'equity' => $equityPlatform && (bool) ($sales['enable_equity_bank'] ?? true),
            'kcb' => (bool) ($sales['enable_kcb_bank'] ?? true),
            'other_bank' => (bool) ($sales['enable_other_bank'] ?? false),
            'cheque' => (($sales['enable_cheque'] ?? true) !== false),
            'credit' => (bool) ($options['include_credit'] ?? false)
                && (($sales['enable_credit_payment'] ?? true) !== false),
        ];
    }

    /**
     * @param  Collection<int, PaymentMethod>|iterable<PaymentMethod>  $methods
     * @param  array<string, mixed>  $sales
     * @param  array{
     *   include_credit?: bool,
     *   mpesa_platform?: bool,
     *   equity_platform?: bool
     * }  $options
     * @return Collection<int, PaymentMethod>
     */
    public static function filter(iterable $methods, array $sales, array $options = []): Collection
    {
        $flags = self::flags($sales, $options);
        $collection = $methods instanceof Collection ? $methods : collect($methods);

        $filtered = $collection
            ->filter(fn (PaymentMethod $method) => (bool) ($method->is_active ?? true))
            ->filter(function (PaymentMethod $method) use ($flags) {
                $code = self::normalizeCode((string) $method->method_code);

                if ($code === 'CASH' || str_contains($code, 'CASH')) {
                    return $flags['cash'];
                }
                if (in_array($code, self::MPESA_CODES, true) || str_contains($code, 'MPESA')) {
                    return $flags['mpesa'];
                }
                if (in_array($code, self::CHEQUE_CODES, true)) {
                    return $flags['cheque'];
                }
                if ($code === 'CREDIT' || str_contains($code, 'CREDIT')) {
                    return $flags['credit'];
                }
                if ($code === 'EQUITY') {
                    return $flags['equity'];
                }
                if ($code === 'KCB') {
                    return $flags['kcb'];
                }
                if (in_array($code, self::OTHER_BANK_CODES, true)) {
                    return $flags['other_bank'];
                }

                // Card / Voucher / Points / custom — not Recording payments fields.
                return false;
            })
            ->values();

        return $filtered->sort(function (PaymentMethod $a, PaymentMethod $b) {
            $rank = self::preferredRank(self::normalizeCode((string) $a->method_code))
                <=> self::preferredRank(self::normalizeCode((string) $b->method_code));
            if ($rank !== 0) {
                return $rank;
            }

            return strcasecmp((string) $a->method_name, (string) $b->method_name);
        })->values();
    }

    private static function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private static function preferredRank(string $code): int
    {
        $idx = array_search($code, self::PREFERRED_ORDER, true);
        if ($idx !== false) {
            return (int) $idx;
        }
        if (in_array($code, self::MPESA_CODES, true)) {
            return (int) array_search('MPESA', self::PREFERRED_ORDER, true);
        }
        if (in_array($code, self::CHEQUE_CODES, true)) {
            return (int) array_search('CHEQUE', self::PREFERRED_ORDER, true);
        }
        if (in_array($code, self::OTHER_BANK_CODES, true)) {
            return (int) array_search('OTHER', self::PREFERRED_ORDER, true);
        }

        return count(self::PREFERRED_ORDER) + 1;
    }
}
