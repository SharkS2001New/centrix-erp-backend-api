<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Damage extends Model
{
    use HasFactory;

    protected $table = 'damages';
    protected $fillable = [
        'product_code', 'branch_id', 'quantity', 'package_type', 'uom_label',
        'stock_location', 'reason', 'reported_by',
    ];
}
