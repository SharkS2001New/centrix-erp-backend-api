<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizationProduct;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryTransaction extends Model
{
    use BelongsToOrganizationProduct;
    use HasFactory;

    protected $table = 'inventory_transactions';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'product_code',
        'stock_location',
        'transaction_type',
        'reference_type',
        'reference_id',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'unit_cost',
        'notes',
        'created_by',
    ];

    const CREATED_AT = 'created_at';
}
