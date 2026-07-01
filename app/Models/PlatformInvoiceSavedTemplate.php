<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformInvoiceSavedTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'template_id',
        'line_items',
        'selected_modules',
        'notes',
        'terms',
        'tax_rate',
        'created_by',
    ];

    protected $casts = [
        'line_items' => 'array',
        'selected_modules' => 'array',
        'tax_rate' => 'decimal:2',
    ];
}
