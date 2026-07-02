<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockTakeSession extends Model
{
    use HasFactory;
    protected $table = "stock_take_sessions";
    public $timestamps = false;
    protected $fillable = array (
  0 => 'branch_id',
  1 => 'session_code',
  2 => 'status',
  3 => 'stock_location',
  4 => 'filter_category_id',
  5 => 'filter_subcategory_id',
  6 => 'filter_supplier_id',
  7 => 'started_by',
  8 => 'completed_by',
  9 => 'completed_at',
  10 => 'notes',
);
    protected $casts = [
        'filter_category_id' => 'integer',
        'filter_subcategory_id' => 'integer',
        'filter_supplier_id' => 'integer',
    ];
}
