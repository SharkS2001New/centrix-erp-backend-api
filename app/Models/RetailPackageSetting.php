<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RetailPackageSetting extends Model
{
    use HasFactory;

    protected $table = 'retail_package_settings';
    public $timestamps = false;
    protected $fillable = [
        'product_code', 'max_qty_measure', 'markup_price', 'min_uom_measure',
        'wholesale_qty_measure', 'wholesale_markup_price', 'max_uom_measure',
    ];
}
