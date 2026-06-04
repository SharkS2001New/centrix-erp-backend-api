<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockTakeSession extends Model
{
    use HasFactory;
    protected $table = "stock_take_sessions";
    protected $fillable = array (
  0 => 'branch_id',
  1 => 'session_code',
  2 => 'status',
  3 => 'stock_location',
  4 => 'started_by',
  5 => 'completed_by',
  6 => 'completed_at',
  7 => 'notes',
);
}
