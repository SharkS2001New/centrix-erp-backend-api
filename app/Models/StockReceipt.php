<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockReceipt extends Model
{
    use HasFactory;

    protected $table = 'stock_receipts';

    public const UPDATED_AT = null;

    protected $fillable = [
        'product_code', 'branch_id', 'organization_id', 'units_received',
        'stock_location', 'invoice_number', 'cost_price', 'original_cost_price', 'received_by',
    ];
}
