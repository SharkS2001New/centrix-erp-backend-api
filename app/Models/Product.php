<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Uom;
use App\Models\Vat;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    protected $table = 'products';
    protected $primaryKey = 'product_code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'product_code', 'product_name', 'subcategory_id', 'unit_id', 'unit_price',
        'last_selling_price', 'last_cost_price', 'discount_type', 'discount_percentage',
        'discount_value', 'product_weight', 'stock_in_shop', 'stock_in_store',
        'supplier_id', 'sell_on_retail', 'vat_id', 'organization_id',
        'reorder_point', 'low_stock_alert_enabled', 'created_by', 'updated_by',
        'deleted_at', 'deleted_by',
    ];

    public function unit()
    {
        return $this->belongsTo(Uom::class, 'unit_id');
    }

    public function vat()
    {
        return $this->belongsTo(Vat::class, 'vat_id');
    }

    protected $casts = [
        'unit_price' => 'float',
        'stock_in_shop' => 'float',
        'stock_in_store' => 'float',
        'low_stock_alert_enabled' => 'boolean',
        'deleted_at' => 'datetime',
    ];
}
