<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReturnRecord extends Model
{
    use HasFactory;

    protected $table = 'returns';
    protected $fillable = [
        'sale_id', 'branch_id', 'product_code', 'quantity', 'uom', 'amount', 'reason',
        'return_type', 'item_code', 'returned_by', 'is_mobile',
    ];
}
