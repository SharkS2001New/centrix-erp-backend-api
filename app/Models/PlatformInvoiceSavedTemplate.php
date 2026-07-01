<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformInvoiceSavedTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'template_id',
        'invoice_options',
        'line_items',
        'selected_modules',
        'notes',
        'terms',
        'tax_rate',
        'created_by',
    ];

    protected $casts = [
        'invoice_options' => 'array',
        'line_items' => 'array',
        'selected_modules' => 'array',
        'tax_rate' => 'decimal:2',
    ];
}
