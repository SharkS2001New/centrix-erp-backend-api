<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockTakeLine extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = "stock_take_lines";
    protected $fillable = array (
  0 => 'session_id',
  1 => 'product_code',
  2 => 'stock_location',
  3 => 'system_quantity',
  4 => 'counted_quantity',
);
}
