<?php

namespace App\Services\Sales;

use App\Models\Organization;

class PricingFormulaSettings
{
    public const RETAIL_LINE = 'retail_line';

    public const WHOLESALE_LINE = 'wholesale_line';

    public const ROUTE_RETAIL = 'route_retail';

    public const ROUTE_WHOLESALE = 'route_wholesale';

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            // Scaled: markup × chunk applications (e.g. half-bags). Once on line: drop * {markup_apps}.
            self::RETAIL_LINE => '{wholesale_total} + {tier_markup} * {markup_apps}',
            // Flat markup once on the whole line. Per qty: use * {qty} instead.
            self::WHOLESALE_LINE => '{wholesale_total} + {tier_markup}',
            self::ROUTE_RETAIL => '{line_total} + {route_markup}',
            self::ROUTE_WHOLESALE => '{line_total} + {route_markup} * {pack_qty}',
        ];
    }

    /**
     * Placeholders available in retail/wholesale line formulas.
     * Prefer writing freely with + − × ÷ and ( ); pick per-qty or once-on-line yourself.
     *
     * @return list<string>
     */
    public static function linePlaceholders(): array
    {
        return [
            // Wholesale base for the sold qty
            'wholesale_total',
            'aggregate_wholesale',
            'base_price',
            'per_small',
            'wholesale_unit',
            // Markup amount from the active tier / package
            'tier_markup',
            'markup',
            'flat_markup',
            // How many times markup applies (chunk/apps). Use 1 for once-on-line.
            'markup_apps',
            'apps',
            'one',
            // Pre-combined helpers
            'scaled_markup',
            'qty_markup',
            // Quantities
            'qty',
            'quantity',
            'pack_qty',
            'packs',
            'conversion_factor',
            'conversion',
            'middle_factor',
        ];
    }

    /** @return list<string> */
    public static function routePlaceholders(): array
    {
        return [
            'line_total',
            'line',
            'route_markup',
            'markup',
            'flat_route',
            'scaled_route',
            'pack_qty',
            'packs',
            'qty',
            'quantity',
            'one',
        ];
    }

    /** @return array<string, list<string>> */
    public static function placeholdersByKey(): array
    {
        return [
            self::RETAIL_LINE => self::linePlaceholders(),
            self::WHOLESALE_LINE => self::linePlaceholders(),
            self::ROUTE_RETAIL => self::routePlaceholders(),
            self::ROUTE_WHOLESALE => self::routePlaceholders(),
        ];
    }

    /** @return array<string, list<array{label: string, formula: string}>> */
    public static function examplesByKey(): array
    {
        return [
            self::RETAIL_LINE => [
                ['label' => 'Per markup chunk (default)', 'formula' => '{wholesale_total} + {tier_markup} * {markup_apps}'],
                ['label' => 'Once on whole line', 'formula' => '{wholesale_total} + {tier_markup}'],
                ['label' => 'Per small unit qty', 'formula' => '{wholesale_total} + {tier_markup} * {qty}'],
                ['label' => 'Per pack', 'formula' => '{wholesale_total} + {tier_markup} * {pack_qty}'],
            ],
            self::WHOLESALE_LINE => [
                ['label' => 'Once on whole line (default)', 'formula' => '{wholesale_total} + {tier_markup}'],
                ['label' => 'Per small unit qty', 'formula' => '{wholesale_total} + {tier_markup} * {qty}'],
                ['label' => 'Per pack', 'formula' => '{wholesale_total} + {tier_markup} * {pack_qty}'],
            ],
            self::ROUTE_RETAIL => [
                ['label' => 'Once on line (default)', 'formula' => '{line_total} + {route_markup}'],
                ['label' => 'Per small unit qty', 'formula' => '{line_total} + {route_markup} * {qty}'],
                ['label' => 'Per pack', 'formula' => '{line_total} + {route_markup} * {pack_qty}'],
            ],
            self::ROUTE_WHOLESALE => [
                ['label' => 'Per pack (default)', 'formula' => '{line_total} + {route_markup} * {pack_qty}'],
                ['label' => 'Once on line', 'formula' => '{line_total} + {route_markup}'],
                ['label' => 'Per small unit qty', 'formula' => '{line_total} + {route_markup} * {qty}'],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function forOrganization(?Organization $organization): array
    {
        $sales = is_array($organization?->module_settings['sales'] ?? null)
            ? $organization->module_settings['sales']
            : [];

        return self::normalize($sales['pricing_formulas'] ?? null);
    }

    /** @return array<string, string> */
    public static function forOrganizationId(?int $organizationId): array
    {
        if (! $organizationId) {
            return self::defaults();
        }

        $org = Organization::query()->find($organizationId);

        return self::forOrganization($org);
    }

    /**
     * @param  mixed  $raw
     * @return array<string, string>
     */
    public static function normalize(mixed $raw): array
    {
        $defaults = self::defaults();
        if (! is_array($raw)) {
            return $defaults;
        }

        $out = $defaults;
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $value = trim((string) $raw[$key]);
            if ($value === '') {
                continue;
            }
            try {
                PricingFormulaEvaluator::validateSyntax($value, self::placeholdersByKey()[$key] ?? []);
                $out[$key] = $value;
            } catch (\Throwable) {
                // Keep default when org formula is invalid.
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @return array<string, string>
     */
    public static function normalizeForSave(mixed $raw): array
    {
        $defaults = self::defaults();
        if (! is_array($raw)) {
            return $defaults;
        }

        $out = [];
        foreach ($defaults as $key => $default) {
            $value = trim((string) ($raw[$key] ?? ''));
            if ($value === '') {
                $out[$key] = $default;
                continue;
            }
            PricingFormulaEvaluator::validateSyntax($value, self::placeholdersByKey()[$key] ?? []);
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Build evaluator variables for a priced line (retail/wholesale).
     *
     * @return array<string, float>
     */
    public static function lineVars(
        float $aggregateWholesale,
        float $tierMarkup,
        float $markupApps,
        float $qty,
        float $packQty,
        float $conversion,
        float $perSmall,
        float $middleFactor,
        float $basePrice,
    ): array {
        $apps = max(0.0, $markupApps);

        return [
            'aggregate_wholesale' => $aggregateWholesale,
            'wholesale_total' => $aggregateWholesale,
            'base_price' => $basePrice,
            'per_small' => $perSmall,
            'wholesale_unit' => $perSmall,
            'tier_markup' => $tierMarkup,
            'markup' => $tierMarkup,
            'flat_markup' => $tierMarkup,
            'markup_apps' => $apps,
            'apps' => $apps,
            'one' => 1.0,
            'scaled_markup' => $tierMarkup * $apps,
            'qty_markup' => $tierMarkup * $qty,
            'qty' => $qty,
            'quantity' => $qty,
            'pack_qty' => $packQty,
            'packs' => $packQty,
            'conversion_factor' => $conversion,
            'conversion' => $conversion,
            'middle_factor' => $middleFactor,
        ];
    }

    /**
     * @return array<string, float>
     */
    public static function routeVars(
        float $lineAmount,
        float $routeMarkup,
        float $packQty,
        float $qty,
    ): array {
        return [
            'line' => $lineAmount,
            'line_total' => $lineAmount,
            'route_markup' => $routeMarkup,
            'markup' => $routeMarkup,
            'flat_route' => $routeMarkup,
            'scaled_route' => $routeMarkup * $packQty,
            'pack_qty' => $packQty,
            'packs' => $packQty,
            'qty' => $qty,
            'quantity' => $qty,
            'one' => 1.0,
        ];
    }
}
