<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalityRecipeIngredient extends Model
{
    protected $table = 'hospitality_recipe_ingredients';

    protected $fillable = [
        'recipe_id',
        'organization_id',
        'ingredient_product_code',
        'quantity',
        'waste_percent',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'waste_percent' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(HospitalityRecipe::class, 'recipe_id');
    }

    public function ingredientProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_product_code', 'product_code');
    }

    /** Quantity to deduct per 1 menu unit, including waste. */
    public function effectiveQuantity(): float
    {
        $qty = max(0, (float) $this->quantity);
        $waste = max(0, (float) $this->waste_percent);

        return round($qty * (1 + ($waste / 100)), 4);
    }
}
