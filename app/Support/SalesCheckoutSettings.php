<?php

namespace App\Support;

class SalesCheckoutSettings
{
    /**
     * Whether unit price may be overridden for a cart line.
     *
     * @param  array<string, mixed>  $sales
     */
    public static function allowsEditableUnitPrice(array $sales, ?string $orderSource): bool
    {
        $source = strtolower(trim((string) ($orderSource ?: 'backoffice')));

        if ($source === 'pos') {
            return ! empty($sales['allow_pos_edit_unit_price']);
        }

        return ! empty($sales['allow_edit_unit_price']);
    }

    /**
     * Whether staff may type a manual line discount for a cart line.
     *
     * @param  array<string, mixed>  $sales
     */
    public static function allowsManualLineDiscount(array $sales, ?string $orderSource): bool
    {
        $source = strtolower(trim((string) ($orderSource ?: 'backoffice')));

        if ($source === 'pos') {
            return ! empty($sales['allow_pos_edit_line_discount']);
        }

        return ! empty($sales['allow_edit_line_discount']);
    }
}
