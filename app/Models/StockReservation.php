<?php
namespace App\Models;

use App\Support\OrganizationIdResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockReservation extends Model
{
    use HasFactory;

    protected $table = 'stock_reservations';
    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(function (StockReservation $reservation) {
            if (! $reservation->branch_id) {
                return;
            }

            if (! $reservation->organization_id || $reservation->isDirty('branch_id')) {
                $reservation->organization_id = OrganizationIdResolver::requireForBranch((int) $reservation->branch_id);
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'branch_id', 'product_code', 'stock_location', 'quantity',
        'cart_id', 'cart_line_id', 'sale_id', 'reserved_by', 'expires_at', 'released_at',
    ];
    protected $casts = [
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_code', 'product_code');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
