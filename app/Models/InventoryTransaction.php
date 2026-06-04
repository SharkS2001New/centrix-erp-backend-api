<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InventoryTransaction extends Model
{
    use HasFactory;

    protected $table = 'inventory_transactions';
    public $timestamps = false;
    protected $fillable = [
        'branch_id', 'product_code', 'stock_location', 'transaction_type',
        'reference_type', 'reference_id', 'quantity_change', 'quantity_before',
        'quantity_after', 'unit_cost', 'notes', 'created_by',
    ];
    const CREATED_AT = 'created_at';
}
