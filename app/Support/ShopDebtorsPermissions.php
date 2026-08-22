<?php

namespace App\Support;

/**
 * Shop Debtors pages — standalone permission module (not under Customers).
 */
class ShopDebtorsPermissions
{
    public const MODULE = 'shop_debtors';

    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    /** @return list<string> */
    public static function buckets(): array
    {
        return [self::UNPAID, self::PARTIAL, self::PAID];
    }

    public static function permissionCodeForBucket(?string $bucket): string
    {
        return self::MODULE.'.'.self::normalizeBucket($bucket ?? self::UNPAID).'.view';
    }

    /** @return list<string> */
    public static function allViewPermissionCodes(): array
    {
        return array_map(
            static fn (string $bucket) => self::permissionCodeForBucket($bucket),
            self::buckets(),
        );
    }

    /** @return list<string> */
    public static function legacyViewPermissionCodes(): array
    {
        return [
            'customers.shop_debtors.view',
            'customers.shop_debtors_unpaid.view',
            'customers.shop_debtors_partial.view',
            'customers.shop_debtors_paid.view',
        ];
    }

    /** @return array{label: string, features: array<string, array{label: string, actions: list<string>}>} */
    public static function registryGroup(): array
    {
        return [
            'label' => 'Shop debtors',
            'features' => [
                self::UNPAID => ['label' => 'Unpaid shop debtors', 'actions' => ['view']],
                self::PARTIAL => ['label' => 'Partially paid shop debtors', 'actions' => ['view']],
                self::PAID => ['label' => 'Paid shop debtors', 'actions' => ['view']],
            ],
        ];
    }

    public static function normalizeBucket(?string $bucket): string
    {
        $key = strtolower(trim(str_replace('-', '_', (string) $bucket)));
        if (in_array($key, ['partially_paid', 'partiallypaid', 'pending_payment'], true)) {
            return self::PARTIAL;
        }
        if (in_array($key, [self::UNPAID, self::PARTIAL, self::PAID], true)) {
            return $key;
        }

        return self::UNPAID;
    }
}
