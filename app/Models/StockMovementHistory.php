<?php
namespace App\Models;

use App\Support\OrganizationIdResolver;
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

    protected static function booted(): void
    {
        static::creating(function (StockMovementHistory $row) {
            if (! empty($row->organization_id) || empty($row->branch_id)) {
                return;
            }
            $row->organization_id = OrganizationIdResolver::requireForBranch((int) $row->branch_id);
        });
    }
}
