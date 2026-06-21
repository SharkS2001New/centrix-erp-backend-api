<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    use HasFactory;
    protected $table = 'price_history';
    public $timestamps = false;
    protected $fillable = [
        'product_code',
        'unit_price',
        'cost_price',
        'discount_pct',
        'changed_by',
        'organization_id',
        'changed_at',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_code', 'product_code');
    }

    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    protected $casts = [
        'changed_at' => 'datetime',
    ];
}
