<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityRecipe;
use App\Models\HospitalityRecipeIngredient;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HospitalityRecipeService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(Organization $org): array
    {
        $recipes = HospitalityRecipe::query()
            ->with('ingredients')
            ->where('organization_id', $org->id)
            ->orderBy('menu_product_code')
            ->get();

        $codes = $recipes->pluck('menu_product_code')
            ->merge($recipes->flatMap(fn (HospitalityRecipe $r) => $r->ingredients->pluck('ingredient_product_code')))
            ->unique()
            ->filter()
            ->values()
            ->all();

        $names = Product::query()
            ->where('organization_id', $org->id)
            ->whereIn('product_code', $codes)
            ->pluck('product_name', 'product_code');

        return $recipes->map(fn (HospitalityRecipe $recipe) => $this->toArray($recipe, $names))->all();
    }

    public function find(Organization $org, int $recipeId): HospitalityRecipe
    {
        return HospitalityRecipe::query()
            ->with('ingredients')
            ->where('organization_id', $org->id)
            ->where('id', $recipeId)
            ->firstOrFail();
    }

    /**
     * @param  array{
     *   menu_product_code: string,
     *   deduct_mode?: string,
     *   is_active?: bool,
     *   notes?: ?string,
     *   ingredients?: list<array{ingredient_product_code: string, quantity: float|int|string, waste_percent?: float|int|string}>
     * }  $data
     */
    public function upsert(Organization $org, array $data, ?int $recipeId = null): HospitalityRecipe
    {
        $menuCode = trim((string) ($data['menu_product_code'] ?? ''));
        if ($menuCode === '') {
            throw ValidationException::withMessages(['menu_product_code' => ['Select a menu item product.']]);
        }

        $menu = Product::query()
            ->where('organization_id', $org->id)
            ->where('product_code', $menuCode)
            ->whereNull('deleted_at')
            ->first();
        if (! $menu) {
            throw ValidationException::withMessages(['menu_product_code' => ['Menu product not found.']]);
        }

        $mode = strtolower(trim((string) ($data['deduct_mode'] ?? 'recipe')));
        if (! in_array($mode, ['recipe', 'direct', 'none'], true)) {
            throw ValidationException::withMessages(['deduct_mode' => ['Use recipe, direct, or none.']]);
        }

        $ingredients = is_array($data['ingredients'] ?? null) ? $data['ingredients'] : [];
        if ($mode === 'recipe' && count($ingredients) < 1) {
            throw ValidationException::withMessages([
                'ingredients' => ['Add at least one ingredient for recipe mode (e.g. unga in kg).'],
            ]);
        }

        return DB::transaction(function () use ($org, $data, $recipeId, $menuCode, $mode, $ingredients) {
            if ($recipeId) {
                $recipe = $this->find($org, $recipeId);
                if ($recipe->menu_product_code !== $menuCode) {
                    $exists = HospitalityRecipe::query()
                        ->where('organization_id', $org->id)
                        ->where('menu_product_code', $menuCode)
                        ->where('id', '!=', $recipe->id)
                        ->exists();
                    if ($exists) {
                        throw ValidationException::withMessages([
                            'menu_product_code' => ['A recipe already exists for this menu item.'],
                        ]);
                    }
                }
            } else {
                $recipe = HospitalityRecipe::query()
                    ->where('organization_id', $org->id)
                    ->where('menu_product_code', $menuCode)
                    ->first();
                if (! $recipe) {
                    $recipe = new HospitalityRecipe([
                        'organization_id' => $org->id,
                        'menu_product_code' => $menuCode,
                    ]);
                }
            }

            $recipe->fill([
                'menu_product_code' => $menuCode,
                'deduct_mode' => $mode,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                'notes' => isset($data['notes']) ? (trim((string) $data['notes']) ?: null) : $recipe->notes,
            ]);
            $recipe->save();

            HospitalityRecipeIngredient::query()->where('recipe_id', $recipe->id)->delete();

            if ($mode === 'recipe') {
                $sort = 0;
                foreach ($ingredients as $row) {
                    $ingCode = trim((string) ($row['ingredient_product_code'] ?? ''));
                    $qty = round((float) ($row['quantity'] ?? 0), 4);
                    if ($ingCode === '' || $qty <= 0) {
                        continue;
                    }
                    if ($ingCode === $menuCode) {
                        throw ValidationException::withMessages([
                            'ingredients' => ['An ingredient cannot be the same as the menu item.'],
                        ]);
                    }
                    $ing = Product::query()
                        ->where('organization_id', $org->id)
                        ->where('product_code', $ingCode)
                        ->whereNull('deleted_at')
                        ->first();
                    if (! $ing) {
                        throw ValidationException::withMessages([
                            'ingredients' => ["Ingredient product not found: {$ingCode}"],
                        ]);
                    }
                    HospitalityRecipeIngredient::create([
                        'recipe_id' => $recipe->id,
                        'organization_id' => $org->id,
                        'ingredient_product_code' => $ingCode,
                        'quantity' => $qty,
                        'waste_percent' => max(0, min(100, (float) ($row['waste_percent'] ?? 0))),
                        'sort_order' => $sort++,
                    ]);
                }
                if ($recipe->ingredients()->count() < 1) {
                    throw ValidationException::withMessages([
                        'ingredients' => ['Add at least one valid ingredient quantity.'],
                    ]);
                }
            }

            return $recipe->fresh('ingredients');
        });
    }

    public function delete(Organization $org, int $recipeId): void
    {
        $recipe = $this->find($org, $recipeId);
        $recipe->delete();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, string>|array<string, string>  $names
     * @return array<string, mixed>
     */
    public function toArray(HospitalityRecipe $recipe, $names = []): array
    {
        $names = collect($names);

        return [
            'id' => $recipe->id,
            'menu_product_code' => $recipe->menu_product_code,
            'menu_product_name' => $names->get($recipe->menu_product_code)
                ?? $recipe->menuProduct?->product_name
                ?? $recipe->menu_product_code,
            'deduct_mode' => $recipe->deduct_mode,
            'is_active' => (bool) $recipe->is_active,
            'notes' => $recipe->notes,
            'ingredients' => $recipe->ingredients->map(fn (HospitalityRecipeIngredient $ing) => [
                'id' => $ing->id,
                'ingredient_product_code' => $ing->ingredient_product_code,
                'ingredient_product_name' => $names->get($ing->ingredient_product_code)
                    ?? $ing->ingredientProduct?->product_name
                    ?? $ing->ingredient_product_code,
                'quantity' => (float) $ing->quantity,
                'waste_percent' => (float) $ing->waste_percent,
                'effective_quantity' => $ing->effectiveQuantity(),
                'sort_order' => (int) $ing->sort_order,
            ])->values()->all(),
        ];
    }
}
