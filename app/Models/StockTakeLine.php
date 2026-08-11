<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTakeLine extends Model
{
    use HasFactory;

    protected $table = 'stock_take_lines';

    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'product_code',
        'stock_location',
        'system_quantity',
        'counted_quantity',
        'is_counted',
    ];

    protected $casts = [
        'system_quantity' => 'float',
        'counted_quantity' => 'float',
        'is_counted' => 'boolean',
    ];

    public function session()
    {
        return $this->belongsTo(StockTakeSession::class, 'session_id');
    }
}
