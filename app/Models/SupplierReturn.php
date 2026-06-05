<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReturn extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'supplier_returns';

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'document_id', 'supplier_id', 'branch_id', 'product_code', 'quantity', 'package_type',
        'uom_label', 'stock_location', 'reason', 'reference_type', 'reference_id',
        'returned_by',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(SupplierReturnDocument::class, 'document_id');
    }
}
