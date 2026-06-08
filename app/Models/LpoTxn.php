<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LpoTxn extends Model
{
    use HasFactory;

    protected $table = 'lpo_txn';
    protected $fillable = [
        'lpo_no', 'product_code', 'ordered_qty', 'uom', 'cost_price',
        'received_qty', 'markup_amount', 'markup_percent',
    ];
}
