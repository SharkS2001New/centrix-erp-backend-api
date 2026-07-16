<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockMovementHistory extends Model
{
    use HasFactory;

    protected $table = 'stock_movement_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'product_code', 'branch_id', 'quantity_moved', 'from_location', 'to_location',
        'notes', 'moved_by', 'move_status',
    ];
}
