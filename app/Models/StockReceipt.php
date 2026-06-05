<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockReceipt extends Model
{
    use HasFactory;

    public $timestamps = true;

    const UPDATED_AT = null;

    protected $table = 'stock_receipts';
    protected $fillable = [
        'product_code', 'branch_id', 'organization_id', 'units_received',
        'stock_location', 'invoice_number', 'cost_price', 'received_by',
    ];
}
