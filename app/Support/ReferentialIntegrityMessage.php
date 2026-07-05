<?php

namespace App\Support;

use Illuminate\Database\QueryException;

class ReferentialIntegrityMessage
{
    /** @var array<string, array<string, string>> parent table => child.column => usage hint */
    private const DELETE_HINTS = [
        'sub_categories' => [
            'products.subcategory_id' => 'product(s). Reassign those products to another sub-category first.',
        ],
        'categories' => [
            'sub_categories.category_id' => 'sub-categor(ies). Delete or reassign those sub-categories first.',
        ],
        'uoms' => [
            'products.unit_id' => 'product(s). Reassign those products to another unit of measure first.',
        ],
        'vats' => [
            'products.vat_id' => 'product(s). Reassign those products to another VAT rate first.',
        ],
    ];

    public static function forDelete(QueryException $exception): ?string
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($sqlState !== '23000' || $driverCode !== 1451) {
            return null;
        }

        $message = $exception->getMessage();

        if (! preg_match(
            '/FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)`/',
            $message,
            $matches,
        )) {
            return 'This record cannot be deleted because other records still reference it. '
                .'Remove or reassign the linked records first.';
        }

        $childColumn = $matches[1];
        $parentTable = $matches[2];
        $childTable = null;

        if (preg_match('/`[^`]+`\.`([^`]+)`/', $message, $childMatch)) {
            $childTable = $childMatch[1];
        }

        if ($childTable !== null) {
            $hint = self::DELETE_HINTS[$parentTable]["{$childTable}.{$childColumn}"] ?? null;
            if ($hint !== null) {
                return "Cannot delete this record — it is still used by {$hint}";
            }
        }

        return 'This record cannot be deleted because other records still reference it. '
            .'Remove or reassign the linked records first.';
    }
}
