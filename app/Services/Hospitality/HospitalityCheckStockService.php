<?php

namespace App\Services\Hospitality;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\HospitalityCheck;
use App\Models\HospitalityCheckLine;
use App\Models\HospitalityRecipe;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Deducts kitchen / bar stock when a hospitality check is settled.
 * Retail sales stock paths are intentionally not used.
 */
class HospitalityCheckStockService
{
    use HandlesInventory;

    public const TXN_TYPE = 'HOSPITALITY_SALE';

    public function __construct(
        protected CapabilityGate $gate,
    ) {}

    public function deductForSettledCheck(HospitalityCheck $check, User $user): void
    {
        $org = Organization::query()->find($check->organization_id);
        if (! $org) {
            return;
        }

        $settings = HospitalityPosSettings::forOrganization($org);
        if (! $settings['stock_deduct_on_settle']) {
            return;
        }

        if ((int) ($check->stock_balanced ?? 0) === 1) {
            return;
        }

        $gate = $this->gate->forOrganization($org);
        if (! $gate->enabled('inventory')) {
            if ($settings['block_settle_if_insufficient']) {
                throw ValidationException::withMessages([
                    'stock' => [
                        'Stock deduct on settle is on, but the Inventory module is not enabled. Enable Inventory or turn off stock deduct in Hospitality settings.',
                    ],
                ]);
            }

            return;
        }

        $location = $settings['stock_location'] === 'store' ? 'store' : 'shop';
        $allowBelow = $this->organizationAllowsBelowStock((int) $org->id)
            || ! $settings['block_settle_if_insufficient'];

        $lines = HospitalityCheckLine::query()->where('check_id', $check->id)->get();
        if ($lines->isEmpty()) {
            return;
        }

        $recipes = HospitalityRecipe::query()
            ->with('ingredients')
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->whereIn('menu_product_code', $lines->pluck('product_code')->unique()->all())
            ->get()
            ->keyBy('menu_product_code');

        /** @var array<string, float> $deductByCode */
        $deductByCode = [];
        $missingRecipes = [];

        foreach ($lines as $line) {
            $code = (string) $line->product_code;
            $soldQty = max(0, (float) $line->qty);
            if ($soldQty <= 0 || $code === '') {
                continue;
            }

            $recipe = $recipes->get($code);
            $mode = $recipe?->deduct_mode;

            if (! $recipe || $mode === 'none') {
                if ($settings['require_recipe_for_stocked_items'] && ! $recipe) {
                    $missingRecipes[] = $line->description ?: $code;
                }
                continue;
            }

            if ($mode === 'direct') {
                $deductByCode[$code] = ($deductByCode[$code] ?? 0) + $soldQty;
                continue;
            }

            // recipe mode
            if ($recipe->ingredients->isEmpty()) {
                $missingRecipes[] = ($line->description ?: $code).' (recipe has no ingredients)';
                continue;
            }

            foreach ($recipe->ingredients as $ing) {
                $need = round($ing->effectiveQuantity() * $soldQty, 4);
                if ($need <= 0) {
                    continue;
                }
                $ingCode = (string) $ing->ingredient_product_code;
                $deductByCode[$ingCode] = ($deductByCode[$ingCode] ?? 0) + $need;
            }
        }

        if ($missingRecipes !== [] && $settings['require_recipe_for_stocked_items']) {
            throw ValidationException::withMessages([
                'stock' => [
                    'Configure recipes for: '.implode(', ', array_unique($missingRecipes)).'. Open Hospitality → Settings → Recipes.',
                ],
            ]);
        }

        if ($deductByCode === []) {
            $check->update([
                'stock_balanced' => true,
                'stock_deducted_at' => now(),
            ]);

            return;
        }

        DB::transaction(function () use ($check, $user, $org, $location, $allowBelow, $deductByCode) {
            $locked = HospitalityCheck::query()->lockForUpdate()->find($check->id);
            if (! $locked || (int) ($locked->stock_balanced ?? 0) === 1) {
                return;
            }

            foreach ($deductByCode as $productCode => $qty) {
                $qty = round((float) $qty, 4);
                if ($qty <= 0) {
                    continue;
                }
                $product = Product::query()
                    ->where('organization_id', $org->id)
                    ->where('product_code', $productCode)
                    ->first();
                $unitCost = max(0, (float) ($product?->last_cost_price ?? 0));

                try {
                    $this->postStockLedger([
                        'organization_id' => $org->id,
                        'branch_id' => $locked->branch_id ?? $user->branch_id,
                        'product_code' => $productCode,
                        'stock_location' => $location,
                        'transaction_type' => self::TXN_TYPE,
                        'reference_type' => 'hospitality_check',
                        'reference_id' => $locked->id,
                        'quantity_change' => -abs($qty),
                        'unit_cost' => $unitCost > 0 ? $unitCost : null,
                        'notes' => 'Hotel POS check '.$locked->check_number,
                        'created_by' => $user->id,
                    ], $allowBelow);
                } catch (InvalidArgumentException $e) {
                    throw ValidationException::withMessages([
                        'stock' => [
                            $e->getMessage().' Configure stock or recipes in Hospitality → Settings.',
                        ],
                    ]);
                }
            }

            $locked->update([
                'stock_balanced' => true,
                'stock_deducted_at' => now(),
            ]);
        });
    }
}
