<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockMovementHistory extends Model
{
    use HasFactory;

    protected $table = 'stock_movement_history';

    /** Table has created_at only — no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_code', 'branch_id', 'quantity_moved', 'from_location', 'to_location',
        'moved_by', 'move_status',
    ];
}
