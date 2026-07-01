<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformInvoice extends Model
{
    protected $fillable = [
        'invoice_number',
        'organization_id',
        'status',
        'template_id',
        'currency',
        'issue_date',
        'due_date',
        'bill_to_name',
        'bill_to_email',
        'bill_to_phone',
        'bill_to_address',
        'bill_to_tax_pin',
        'bill_to_company_code',
        'seller',
        'line_items',
        'selected_modules',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total',
        'notes',
        'terms',
        'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'seller' => 'array',
        'line_items' => 'array',
        'selected_modules' => 'array',
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
