<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HospitalityRecipe extends Model
{
    protected $table = 'hospitality_recipes';

    protected $fillable = [
        'organization_id',
        'menu_product_code',
        'deduct_mode',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function ingredients(): HasMany
    {
        return $this->hasMany(HospitalityRecipeIngredient::class, 'recipe_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function menuProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'menu_product_code', 'product_code');
    }
}
